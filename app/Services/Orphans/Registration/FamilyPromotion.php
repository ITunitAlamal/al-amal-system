<?php

namespace App\Services\Orphans\Registration;

use App\Models\Orphan;
use App\Models\OrphanGuardian;
use App\Models\OrphanGuardianship;
use App\Models\Person;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Configuration\GazaAddress;
use App\Services\Orphans\ArabicText;
use App\Services\Orphans\Identity;
use App\Services\Orphans\Persons;
use App\Services\Orphans\PortalApplications;
use App\Services\Orphans\Registry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Promotes one approved child of a family request into the operational registry.
 *
 * Shared people are resolved once per request and remembered in `portal_applications.promotion`:
 * - the father and the mother;
 * - the household;
 * - each guardian.
 * Siblings approved later reuse the same rows. A person whose identity number is already registered is reused and
 * never overwritten; differences are shown to the reviewer as conflicts that must be resolved first. Reported
 * answers stay «مُفاد», and verification is a separate staff act.
 */
final class FamilyPromotion
{
    public function __construct(private readonly Registry $registry, private readonly Persons $persons, private readonly GazaAddress $geo, private readonly AuditService $audit) {}

    /**
     * Identity numbers in the request that already belong to a registered person with a different name, plus
     * existing parent links of a matched child that point to someone else.
     *
     * @return list<array{key: string, label: string, message: string}>
     */
    public function conflicts(object $application, array $form): array
    {
        $found = [];
        $labels = ['request.applicant' => 'مقدم الطلب', 'father' => 'الأب', 'mother' => 'الأم'];
        foreach (RegistrationForm::people($form) as $key => $person) {
            $existing = $this->existingPerson($person);
            if (! $existing) {
                continue;
            }
            $given = RegistrationForm::normalizedName($person);
            $stored = ArabicText::normalize(trim($existing->first_name.' '.$existing->family_name)) ?: ArabicText::normalize($existing->full_name);
            if ($given !== '' && $stored !== '' && $given !== $stored) {
                $found[] = ['key' => $key, 'label' => $labels[$key] ?? (str_starts_with($key, 'children') ? 'يتيم' : (str_starts_with($key, 'guardians') ? 'وصي' : 'فرد أسرة')),
                    'message' => 'رقم الهوية المدخل لـ«'.(RegistrationForm::displayName($person) ?: 'دون اسم').'» مسجل في النظام باسم «'.$existing->full_name.'». عند الاعتماد يُستخدم السجل الموجود ولا يُغيَّر اسمه.'];
            }
        }
        foreach ($form['children'] ?? [] as $child) {
            $orphanId = $this->existingPerson($child) ? DB::table('orphans')->where('person_id', $this->existingPerson($child)->id)->value('id') : null;
            foreach (['father', 'mother'] as $relation) {
                $parent = $this->existingPerson($form[$relation] ?? []);
                $link = $orphanId ? DB::table('orphan_parent_links')->where('orphan_id', $orphanId)->where('relation', $relation)->value('person_id') : null;
                if ($link && $parent && (int) $link !== (int) $parent->id) {
                    $found[] = ['key' => 'children', 'label' => 'يتيم', 'message' => 'ملف «'.RegistrationForm::displayName($child).'» القائم مرتبط بـ'.($relation === 'father' ? 'أب' : 'أم').' آخر غير المذكور في الطلب.'];
                }
            }
        }

        return $found;
    }

    /**
     * @param  array  $decision  starts_on, ends_on, authority_kind_value_id, legal_basis, evidence_note, note, orphan_id?, resolve_conflicts
     * @return array{0: Orphan, 1: OrphanGuardianship}
     */
    public function promote(object $application, object $child, array $decision, User $actor): array
    {
        $application = DB::table('portal_applications')->where('id', $application->id)->lockForUpdate()->first();
        $form = FamilyRegistrations::form($application);
        $card = collect($form['children'] ?? [])->firstWhere('key', $child->card_key);
        if (! $card) {
            throw ValidationException::withMessages(['child' => 'بطاقة هذا الطفل غير موجودة في الطلب.']);
        }
        if ($this->conflicts($application, $form) !== [] && empty($decision['resolve_conflicts'])) {
            throw ValidationException::withMessages(['resolve_conflicts' => 'في الطلب تعارض هوية. راجع التعارضات وأكد أنك تحققت منها قبل الاعتماد.']);
        }
        $map = json_decode((string) $application->promotion, true) ?: [];
        $context = ['application' => $application, 'form' => $form, 'actor' => $actor];

        $household = $this->household($map, $context);
        $father = $this->parent('father', $map, $context, $household);
        $mother = $this->parent('mother', $map, $context, $household);
        $guardian = $this->guardian($card['guardian_key'] ?? '', $map, $context, $household, $mother);
        abort_unless($guardian, 422, 'لم يُحدد وصي لهذا الطفل في الطلب.');

        $g = $card['guardianship'] ?? [];
        $link = ['link_status' => 'approved', 'kinship_value_id' => $card['kinship_value_id'] ?? $child->kinship_value_id,
            'authority_kind_value_id' => $decision['authority_kind_value_id'] ?? ($g['authority_kind_value_id'] ?? null), 'legal_basis' => $decision['legal_basis'] ?? null,
            'starts_on' => $decision['starts_on'], 'ends_on' => $decision['ends_on'] ?? ($g['ends_on'] ?? null), 'source' => $application->source === 'portal' ? 'portal' : 'staff',
            'evidence_note' => $decision['evidence_note'] ?? ($g['evidence_note'] ?? 'طلب تسجيل '.$application->reference_no), 'decision_note' => $decision['note'] ?? null];

        $orphanId = $decision['orphan_id'] ?? $child->matched_orphan_id;
        if ($orphanId) {
            $orphan = Orphan::query()->lockForUpdate()->findOrFail($orphanId);
            $guardianship = $this->approveExisting($orphan, $guardian, $link, $actor);
            foreach (['father' => $father, 'mother' => $mother] as $relation => $person) {
                if ($person && ! DB::table('orphan_parent_links')->where('orphan_id', $orphan->id)->where('relation', $relation)->exists()) {
                    $this->registry->linkParent($orphan, $relation, ['person_id' => $person->id, 'basis' => 'declared'], $actor);
                }
            }
        } else {
            $person = $this->person($card, $actor, ['civil_registry_ref' => $card['civil_registry_ref'] ?? null]);
            if (Orphan::query()->where('person_id', $person->id)->exists()) {
                throw ValidationException::withMessages(['child' => 'لهذا الطفل ملف يتيم قائم؛ اختر الملف الموجود عند الاعتماد.']);
            }
            $orphan = $this->registry->createOrphan([], ['person_id' => $person->id, 'registration_source' => $application->source === 'portal' ? 'portal' : 'staff'],
                ['guardian_id' => $guardian->id, ...$link], array_filter(['father' => $father ? ['person_id' => $father->id] : null, 'mother' => $mother ? ['person_id' => $mother->id] : null]), $actor);
            $guardianship = OrphanGuardianship::query()->where('orphan_id', $orphan->id)->where('guardian_id', $guardian->id)->whereIn('status', OrphanGuardianship::LIVE)->firstOrFail();
        }
        // Court documentation is the reported status only («نعم / لا / قيد الإخراج / غير معروف»); the approval above is the decision.
        $guardianship->forceFill(['issuing_authority' => $g['issuing_authority'] ?? $guardianship->issuing_authority, 'decision_no' => $g['decision_no'] ?? $guardianship->decision_no,
            'court_status_value_id' => $g['court_status_value_id'] ?? $guardianship->court_status_value_id,
            'kinship_note' => filled($card['kinship_note'] ?? null) ? mb_substr(trim((string) $card['kinship_note']), 0, 200) : $guardianship->kinship_note])->save();
        $this->childDetails($orphan, $card, $context, $household);
        $this->moveDocuments($application, $map, 'child:'.$card['key'], $orphan->person_id, $guardianship->id);
        $this->registry->deriveClass($orphan->fresh());
        $this->registry->refreshCompleteness($orphan->fresh());
        DB::table('portal_applications')->where('id', $application->id)->update(['promotion' => json_encode($map), 'updated_at' => now()]);
        $this->audit->recordRow('orphans.registration.promoted', 'orphans', $orphan->id, null, ['application' => $application->reference_no, 'guardian' => $guardian->reference_no], $actor->id, request()?->ip());

        return [$orphan, $guardianship];
    }

    private function approveExisting(Orphan $orphan, OrphanGuardian $guardian, array $link, User $actor): OrphanGuardianship
    {
        $existing = OrphanGuardianship::query()->where('orphan_id', $orphan->id)->where('guardian_id', $guardian->id)->whereIn('status', OrphanGuardianship::LIVE)->first();
        if ($existing && $existing->status !== 'approved') {
            $this->registry->decideGuardianship($existing, 'approve', $link, $actor);

            return $existing->fresh();
        }

        return $existing ?? $this->registry->addGuardianship($orphan, $guardian, $link, $actor);
    }

    /** The household (social and residential only), its addresses and displacement history, finances and other members. */
    private function household(array &$map, array $context): ?int
    {
        if (isset($map['household'])) {
            return (int) $map['household'];
        }
        ['application' => $application, 'form' => $form, 'actor' => $actor] = $context;
        $h = $form['household'] ?? [];
        $r = $form['residence'] ?? [];
        if (! array_filter($h) && ! array_filter($r)) {
            return null;
        }
        $id = DB::table('households')->insertGetId([
            'public_id' => (string) Str::ulid(), 'reference_no' => Registry::reference('HH', 'household_reference_seq'), 'display_name' => $h['display_name'] ?? null,
            'members_count' => $h['declared_members'] ?? null, 'male_count' => $h['male_count'] ?? null, 'female_count' => $h['female_count'] ?? null,
            'unknown_sex_count' => $h['unknown_sex_count'] ?? null, 'studying_count' => $h['studying_count'] ?? null, 'employed_count' => $h['employed_count'] ?? null,
            'dependents_count' => $h['dependents_count'] ?? null, 'earners_count' => $h['earners_count'] ?? null, 'assessed_on' => $h['assessed_on'] ?? today()->toDateString(),
            'count_exception_reason' => $h['count_exception_reason'] ?? null, 'living_conditions' => $h['living_conditions'] ?? null, 'utilities_access' => $h['utilities_access'] ?? null,
            'special_needs_count' => $h['special_needs_count'] ?? null, 'head_relation_value_id' => $h['head_relation_value_id'] ?? null,
            'contact_phone' => filled($h['contact_phone'] ?? null) ? Identity::clean($h['contact_phone']) : null, 'last_moved_on' => $r['last_moved_on'] ?? null,
            'displacement_status' => $r['displaced'] ?? 'unknown', 'displaced_since' => $r['displaced_since'] ?? null, 'displacement_count' => $r['displacement_count'] ?? null,
            'external_aid_status' => $h['external_aid'] ?? 'unknown', 'application_id' => $application->id, 'status' => 'active',
            'legacy_file_no' => filled($h['legacy_file_no'] ?? null) ? trim((string) $h['legacy_file_no']) : null,
            'location_missing_reason' => $r['location_missing_reason'] ?? null, 'contact_unreachable_reason' => $h['contact_unreachable_reason'] ?? null,
            'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $map['household'] = $id;
        $owner = ['household_id' => $id];
        if (RegistrationForm::hasPlace($r['original'] ?? [])) {
            $this->address($owner, 'original', $r['original'], $actor, ['housing_type_value_id' => $r['original']['housing_type_value_id'] ?? null,
                'building_condition_value_id' => $r['original']['building_condition_value_id'] ?? null]);
        }
        foreach ($r['history'] ?? [] as $move) {
            if (RegistrationForm::hasPlace($move)) {
                $this->address($owner, 'displacement', $move, $actor, ['starts_on' => $move['moved_on'] ?? null, 'ends_on' => $move['left_on'] ?? null, 'move_reason' => $move['reason'] ?? null]);
            }
        }
        if (RegistrationForm::hasPlace($r['current'] ?? [])) {
            $this->address($owner, 'current', $r['current'], $actor, ['shelter_type_value_id' => $r['shelter_type_value_id'] ?? null,
                'tenure_value_id' => $h['tenure_value_id'] ?? null, 'stability' => $r['current']['stability'] ?? null,
                'host_note' => ($r['hosted'] ?? null) === 'yes' ? ($r['host_note'] ?? null) : null, 'accessibility_needs' => $r['accessibility_needs'] ?? null, 'source' => $r['source'] ?? null,
                'housing_condition' => $r['current']['housing_condition'] ?? null, 'contact_accessibility' => $r['contact_accessibility'] ?? null,
                'rooms_count' => $r['current']['rooms_count'] ?? null, 'building_area_sqm' => $r['current']['building_area_sqm'] ?? null,
                'building_type_value_id' => $r['current']['building_type_value_id'] ?? null, 'building_condition_value_id' => $r['current']['building_condition_value_id'] ?? null,
                'furnishing_condition_value_id' => $r['current']['furnishing_condition_value_id'] ?? null, 'dwelling_condition_value_id' => $r['current']['dwelling_condition_value_id'] ?? null,
                'starts_on' => ($r['displaced'] ?? null) === 'yes' ? ($r['displaced_since'] ?? null) : null]);
        }
        $items = array_values(array_filter($h['income_items'] ?? [], fn (array $i): bool => filled($i['source_value_id'] ?? null) || filled($i['label'] ?? null)));
        if (collect(['total_income', 'economic_status_value_id', 'assessed_economic_status_value_id', 'unmet_needs', 'expenses_amount', 'rent_amount', 'employment_stability'])->contains(fn ($k) => filled($h[$k] ?? null)) || ! empty($h['income_source_ids']) || $items !== []) {
            $assessment = $this->economic($owner, 'current', ['income_amount' => $h['total_income'] ?? null, 'currency' => $h['currency'] ?? null, 'income_period' => $h['income_period'] ?? 'monthly',
                'income_source_ids' => $h['income_source_ids'] ?? null, 'economic_status_value_id' => $h['economic_status_value_id'] ?? null, 'dependents_count' => $h['dependents_count'] ?? null,
                'earners_count' => $h['earners_count'] ?? null, 'expenses_amount' => $h['expenses_amount'] ?? null, 'unmet_needs' => $h['unmet_needs'] ?? null, 'notes' => $h['income_note'] ?? null,
                'rent_amount' => $h['rent_amount'] ?? null, 'stability' => $h['employment_stability'] ?? null, 'assessed_status_value_id' => $h['assessed_economic_status_value_id'] ?? null,
                'reference_date' => $h['assessed_on'] ?? null], $context);
            // Income per source: an amount only when declared known; otherwise «لا يوجد» or «غير معروف», never zero.
            foreach ($items as $item) {
                $status = $item['amount_status'] ?? (filled($item['amount'] ?? null) ? 'known' : 'unknown');
                DB::table('economic_income_items')->insert(['economic_assessment_id' => $assessment, 'income_source_value_id' => $item['source_value_id'] ?? null,
                    'label' => filled($item['label'] ?? null) ? trim((string) $item['label']) : null, 'amount_status' => $status,
                    'amount' => $status === 'known' ? $item['amount'] : null, 'currency' => $status === 'known' ? ($item['currency'] ?? null) : null,
                    'period' => $item['period'] ?? null, 'note' => $item['note'] ?? null, 'created_by' => $actor->id, 'created_at' => now()]);
            }
        }
        // Family-level aid once for the household; aid named for one child is recorded with that child (never per sibling).
        foreach ($h['aid'] ?? [] as $aid) {
            if (filled($aid['organization'] ?? null) && blank($aid['child_key'] ?? null)) {
                $this->support(['household_id' => $id], 'aid', $aid, $context);
            }
        }
        foreach ($h['members'] ?? [] as $member) {
            if (RegistrationForm::hasName($member)) {
                $person = $this->person($member, $actor);
                $this->member($id, $person->id, $member['relation_value_id'] ?? null, $actor, ['is_studying' => self::bool($member['is_studying'] ?? null),
                    'is_employed' => self::bool($member['is_employed'] ?? null), 'is_dependent' => self::bool($member['is_dependent'] ?? null), 'starts_on' => $member['since'] ?? null]);
            }
        }
        $this->moveDocuments($application, $map, 'household', null, null, $id);

        return $id;
    }

    /** Father or mother: one person row for every sibling, with life-status event, education and economic history. */
    private function parent(string $relation, array &$map, array $context, ?int $household): ?Person
    {
        if (isset($map[$relation])) {
            return Person::find($map[$relation]);
        }
        ['application' => $application, 'form' => $form, 'actor' => $actor] = $context;
        $row = $form[$relation] ?? [];
        if (! RegistrationForm::hasName($row) && blank($row['id_number'] ?? null)) {
            return null;
        }
        $education = $row['education'] ?? [];
        $person = $this->person($row, $actor, ['sex' => $relation === 'father' ? 'male' : 'female', 'marital_status_value_id' => $row['marital_status_value_id'] ?? null,
            'marital_status_since' => $row['marital_status_since'] ?? null,
            'contact_channel' => $row['contact_channel'] ?? null, 'education_level_value_id' => $education['level_value_id'] ?? null,
            'education_specialization' => $education['specialization'] ?? null, 'education_institution' => $education['institution'] ?? null, 'vocational_training' => $education['vocational'] ?? null]);
        $map[$relation] = $person->id;
        $status = $row['vital_status'] ?? null;
        if ($status && ($person->wasRecentlyCreated || $person->vital_status !== $status)) {
            $death = $row['death'] ?? [];
            $missing = $row['missing'] ?? [];
            $this->persons->recordStatus($person, [
                'status' => $status, 'event_date' => $status === 'deceased' ? ($death['date'] ?? null) : ($status === 'missing' ? ($missing['last_seen_on'] ?? null) : null),
                'place' => $status === 'deceased' ? ($death['place'] ?? null) : ($status === 'missing' ? ($missing['last_seen_place'] ?? null) : null),
                'cause' => $status === 'deceased' ? ($death['cause_note'] ?? null) : null, 'cause_value_id' => $status === 'deceased' ? ($death['cause_value_id'] ?? null) : null,
                'certificate_no' => $status === 'deceased' ? ($death['certificate_no'] ?? null) : null,
                'reported_on' => $missing['reported_on'] ?? null, 'reported_to' => $missing['reported_to'] ?? null, 'report_ref' => $missing['report_ref'] ?? null,
                'source' => $row['status_source'] ?? null, 'confidence' => $row['status_confidence'] ?? null,
                'official_cause' => $status === 'deceased' ? ($death['official_cause'] ?? null) : null, 'verification' => 'reported',
                'evidence_note' => mb_substr(implode(' — ', array_filter(['طلب تسجيل '.$application->reference_no,
                    $status === 'deceased' ? ($death['evidence_note'] ?? null) : ($status === 'missing' ? ($missing['details'] ?? null) : null),
                    $status === 'deceased' && filled($death['documents'] ?? null) ? 'وثائق الوفاة: '.(RegistrationForm::YNU_LABELS[$death['documents']] ?? $death['documents']) : null])), 0, 2000),
            ], $actor->id, $person->wasRecentlyCreated || in_array($person->vital_status, ['unknown', null], true));
        }
        $economic = $row['economic'] ?? [];
        $caregiving = $relation === 'mother' ? ($row['caregiving'] ?? []) : [];
        if (array_filter($economic, fn ($v) => filled($v)) || array_filter($caregiving, fn ($v) => filled($v))) {
            $this->economic(['person_id' => $person->id], $relation === 'father' && $status === 'deceased' ? 'pre_death' : 'current', [
                ...collect($economic)->only(['occupation', 'employment_status_value_id', 'employed', 'employer', 'stability', 'income_amount', 'currency', 'income_regularity', 'other_income', 'dependents_count', 'notes', 'confidence', 'sector', 'income_band_value_id'])->all(),
                'benefits_status' => $economic['benefits'] ?? null, 'benefits_note' => $economic['benefits_note'] ?? null,
                'caregiving_capacity' => $caregiving['capacity'] ?? null, 'caregiving_note' => $caregiving['note'] ?? null,
                'source' => $economic['source'] ?? 'self_reported', 'income_period' => filled($economic['income_amount'] ?? null) ? 'monthly' : null,
                'supports_household' => self::bool($economic['supported_household'] ?? null), 'breadwinner_loss' => self::bool($economic['breadwinner_loss'] ?? null),
            ], $context);
        }
        if ($relation === 'father' && filled($row['residence_before'] ?? null)) {
            DB::table('registry_addresses')->insert(['person_id' => $person->id, 'kind' => 'last_known', 'address_text' => mb_substr($row['residence_before'], 0, 500),
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($relation === 'mother') {
            if (($row['resides_with_household'] ?? null) === 'yes' && $household) {
                $this->member($household, $person->id, $this->kinship('mother'), $actor);
            } elseif (($row['resides_with_household'] ?? null) === 'no' && RegistrationForm::hasPlace($row['address'] ?? [])) {
                $this->address(['person_id' => $person->id], 'current', $row['address'], $actor, []);
            }
            $care = $row['care'] ?? [];
            if (filled($care['support_needs'] ?? null) || filled($care['accommodation'] ?? null)) {
                DB::table('care_records')->insert(['person_id' => $person->id, 'category' => 'other', 'summary' => mb_substr((string) ($care['support_needs'] ?? 'احتياجات دعم'), 0, 300),
                    'accommodations' => filled($care['accommodation'] ?? null) ? encrypt($care['accommodation']) : null, 'source' => 'caregiver', 'verification' => 'reported',
                    'is_active' => true, 'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $this->moveDocuments($application, $map, $relation, $person->id);

        return $person;
    }

    /** The guardian named on the child card: the applicant, the mother (same person row) or another person. */
    private function guardian(string $key, array &$map, array $context, ?int $household, ?Person $mother): ?OrphanGuardian
    {
        if (isset($map['guardian:'.$key])) {
            return OrphanGuardian::find($map['guardian:'.$key]);
        }
        ['application' => $application, 'form' => $form, 'actor' => $actor] = $context;
        $entry = collect($form['guardians'] ?? [])->firstWhere('key', $key);
        if (! $entry) {
            return null;
        }
        $role = $entry['role'] ?? 'other';
        if ($role === 'applicant' && $application->source === 'portal') {
            $guardian = app(PortalApplications::class)->ensureGuardian($application, $actor);
        } else {
            $person = match ($role) {
                'mother' => $mother ?? throw ValidationException::withMessages(['guardian' => 'الوصي هو الأم، لكن بيانات الأم غير مدخلة في الطلب.']),
                'applicant' => $this->person($form['request']['applicant'] ?? [], $actor),
                default => $this->person($entry, $actor),
            };
            $guardian = $this->registry->createGuardian([], $person->id, [], $actor);
        }
        $map['guardian:'.$key] = $guardian->id;
        $personId = (int) $guardian->person_id;
        if ($role !== 'mother') {
            $education = $entry['education'] ?? [];
            $applicant = $role === 'applicant' ? ($form['request']['applicant'] ?? []) : [];
            $updates = array_filter(['contact_channel' => $entry['contact_channel'] ?? ($applicant['contact_channel'] ?? null), 'email' => $entry['email'] ?? ($applicant['email'] ?? null),
                'education_level_value_id' => $education['level_value_id'] ?? null,
                'education_specialization' => $education['specialization'] ?? null], fn ($v) => filled($v));
            // Fill blanks only: an existing person's recorded answers are not replaced by a new request.
            foreach ($updates as $column => $value) {
                DB::table('persons')->where('id', $personId)->whereNull($column)->update([$column => $value, 'updated_at' => now()]);
            }
            $economic = $entry['economic'] ?? [];
            if (array_filter($economic, fn ($v) => filled($v))) {
                $this->economic(['person_id' => $personId], 'current', [...collect($economic)->only(['employed', 'occupation', 'stability', 'income_amount', 'currency', 'other_income', 'dependents_count', 'housing_note', 'notes'])->all(),
                    'income_period' => filled($economic['income_amount'] ?? null) ? 'monthly' : null, 'source' => 'self_reported'], $context);
            }
            if (($entry['resides_with_household'] ?? null) === 'yes' && $household) {
                $this->member($household, $personId, null, $actor);
            } elseif (RegistrationForm::hasPlace($entry['address'] ?? []) && ! DB::table('registry_addresses')->where('person_id', $personId)->where('kind', 'current')->whereNull('ends_on')->exists()) {
                $this->address(['person_id' => $personId], 'current', $entry['address'], $actor, []);
            }
        }
        if ($household) {
            DB::table('households')->where('id', $household)->whereNull('contact_person_id')->update(['contact_person_id' => $personId, 'updated_at' => now()]);
        }
        $this->moveDocuments($application, $map, 'guardian:'.$key, $personId);
        if ($role === 'applicant') {
            $this->moveDocuments($application, $map, 'applicant', $personId);
        }

        return $guardian;
    }

    /** Child education, health (restricted), needs, external sponsorship and household membership. */
    private function childDetails(Orphan $orphan, array $card, array $context, ?int $household): void
    {
        ['application' => $application, 'actor' => $actor] = $context;
        $e = $card['education'] ?? [];
        if (array_filter($e, fn ($v) => filled($v))) {
            DB::table('education_records')->insert(['orphan_id' => $orphan->id, 'academic_year' => $e['academic_year'] ?? null, 'stage_value_id' => $e['stage_value_id'] ?? null,
                'grade' => $e['grade'] ?? null, 'grade_value_id' => $e['grade_value_id'] ?? null, 'achievement_value_id' => $e['achievement_value_id'] ?? null, 'school_name' => $e['school_name'] ?? null, 'status_value_id' => $e['status_value_id'] ?? null,
                'institution_type_value_id' => $e['institution_type_value_id'] ?? null, 'modality' => $e['modality'] ?? null, 'performance' => $e['performance'] ?? null,
                'attendance_note' => $e['attendance'] ?? null, 'support_needs' => $e['barriers'] ?? null, 'nonattendance_reason' => $e['nonattendance_reason'] ?? null,
                'transport_need' => $e['transport_need'] ?? null, 'assistive_need' => $e['assistive_need'] ?? null, 'specialization' => $e['specialization'] ?? null,
                'source' => $application->source === 'portal' ? 'caregiver' : 'staff', 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $health = $card['health'] ?? [];
        $healthCode = RegistrationForm::code($health['status_value_id'] ?? null);
        if ($healthCode && ! in_array($healthCode, ['no_known_issue', 'unknown'], true)) {
            DB::table('care_records')->insert(['person_id' => $orphan->person_id, 'category' => $healthCode === 'disability' ? 'disability' : ($healthCode === 'chronic' ? 'chronic' : 'health'),
                'summary' => 'إفادة طلب التسجيل '.$application->reference_no, 'clinical_details' => filled($health['details'] ?? null) ? encrypt($health['details']) : null,
                'disability_type_value_id' => $health['disability_type_value_id'] ?? null, 'severity' => $health['severity'] ?? null, 'regular_follow_up' => $health['regular_follow_up'] ?? null,
                'follow_up_frequency' => $health['follow_up_frequency'] ?? null, 'access_barrier' => $health['access_needs'] ?? null,
                'accommodations' => filled($health['accommodations'] ?? null) ? encrypt($health['accommodations']) : null,
                'source' => $health['source'] ?? 'caregiver', 'assessed_on' => $health['assessed_on'] ?? null, 'verification' => 'reported', 'is_active' => true, 'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            foreach (['medication' => $health['medications'] ?? null, 'assistive_device' => $health['assistive_devices'] ?? null] as $category => $text) {
                if (filled($text)) {
                    DB::table('care_records')->insert(['person_id' => $orphan->person_id, 'category' => $category, 'summary' => 'إفادة طلب التسجيل', 'clinical_details' => encrypt($text),
                        'source' => $health['source'] ?? 'caregiver', 'verification' => 'reported', 'is_active' => true, 'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        $needs = array_filter($card['needs'] ?? [], fn ($v) => filled($v));
        $social = $card['social'] ?? [];
        $researcher = filled($social['researcher_notes'] ?? null) ? 'ملاحظات الباحث ('.$application->reference_no.'): '.$social['researcher_notes'] : null;
        $orphan->forceFill([
            'loss_circumstances' => filled($social['loss_circumstances'] ?? null) ? $social['loss_circumstances'] : $orphan->loss_circumstances,
            'notes' => $researcher ? trim(($orphan->notes ? $orphan->notes."\n" : '').$researcher) : $orphan->notes, 'health_status_value_id' => $health['status_value_id'] ?? $orphan->health_status_value_id, 'application_id' => $orphan->application_id ?? $application->id,
            'external_sponsorship_status' => $card['sponsorship']['status'] ?? ($orphan->external_sponsorship_status ?? 'unknown'),
            'reported_needs' => $needs ? [...$needs, 'source' => $application->source === 'portal' ? 'guardian' : 'staff', 'reported_on' => today()->toDateString(), 'application' => $application->reference_no] : $orphan->reported_needs,
        ])->save();
        if (($card['sponsorship']['status'] ?? null) === 'yes' && filled($card['sponsorship']['organization'] ?? null)) {
            $this->support(['orphan_id' => $orphan->id], 'sponsorship', $card['sponsorship'], $context);
        }
        foreach ($context['form']['household']['aid'] ?? [] as $aid) {
            if (($aid['child_key'] ?? null) === $card['key'] && filled($aid['organization'] ?? null)) {
                $this->support(['orphan_id' => $orphan->id], 'aid', $aid, $context);
            }
        }
        if ($household && ($card['resides_with_household'] ?? true) !== false) {
            $this->member($household, (int) $orphan->person_id, $this->kinship('child'), $actor);
        }
    }

    /**
     * Reuses the registered person with the same document identity (never edited), otherwise creates one.
     */
    private function person(array $row, User $actor, array $extra = []): Person
    {
        if ($existing = $this->existingPerson($row)) {
            return $existing;
        }
        $fields = collect($row)->only(['first_name', 'father_name', 'grandfather_name', 'family_name', 'extra_names', 'full_name', 'name_en', 'id_type_value_id', 'id_number',
            'sex', 'date_of_birth', 'nationality_value_id', 'place_of_birth', 'phone', 'alt_phone'])->all();
        $person = $this->persons->save([...$fields, 'sex' => $fields['sex'] ?? ($extra['sex'] ?? null)], null, $actor->id, 'person');
        // No number: the stated reason is kept instead (never a placeholder number).
        $extra['id_missing_reason'] = blank($row['id_number'] ?? null) ? ($row['id_missing_reason'] ?? null) : null;
        $extra = array_filter(collect($extra)->except('sex')->all(), fn ($v) => filled($v));
        if ($extra) {
            $person->forceFill($extra)->save();
        }

        return $person;
    }

    private function existingPerson(array $row): ?Person
    {
        [$format, $clean] = Identity::classify($row['id_number'] ?? null, filled($row['id_type_value_id'] ?? null) ? (int) $row['id_type_value_id'] : null);

        return in_array($format, ['valid_format', 'other_type'], true)
            ? Person::query()->where('id_hash', Identity::hash($clean))->whereIn('id_format', ['valid_format', 'other_type'])->first() : null;
    }

    private function address(array $owner, string $kind, array $input, User $actor, array $extra): void
    {
        $normalized = $this->geo->normalize($input, null, '', false);
        $row = $this->registry->saveAddress($owner, $kind, ['governorate_value_id' => $normalized['governorate_value_id'] ?? null, 'locality_value_id' => $normalized['locality_value_id'] ?? null,
            'area_value_id' => $normalized['area_value_id'] ?? null, 'subarea_value_id' => $normalized['subarea_value_id'] ?? null, 'detail' => $normalized['address_details'] ?? null,
            'shelter_type_value_id' => $extra['shelter_type_value_id'] ?? null, 'tenure_value_id' => $extra['tenure_value_id'] ?? null,
            'starts_on' => $extra['starts_on'] ?? null, 'ends_on' => $extra['ends_on'] ?? null], $actor->id);
        $row->forceFill(array_filter(['street' => $input['street'] ?? null, 'landmark' => $input['landmark'] ?? null, 'housing_condition' => $extra['housing_condition'] ?? null,
            'move_reason' => $extra['move_reason'] ?? null, 'contact_accessibility' => $extra['contact_accessibility'] ?? null, 'stability' => $extra['stability'] ?? null,
            'rooms_count' => $extra['rooms_count'] ?? null, 'building_area_sqm' => $extra['building_area_sqm'] ?? null, 'building_type_value_id' => $extra['building_type_value_id'] ?? null,
            'building_condition_value_id' => $extra['building_condition_value_id'] ?? null, 'furnishing_condition_value_id' => $extra['furnishing_condition_value_id'] ?? null,
            'housing_type_value_id' => $extra['housing_type_value_id'] ?? null, 'dwelling_condition_value_id' => $extra['dwelling_condition_value_id'] ?? null,
            'host_note' => $extra['host_note'] ?? null, 'accessibility_needs' => $extra['accessibility_needs'] ?? null, 'source' => $extra['source'] ?? null], fn ($v) => filled($v)))->save();
    }

    private function economic(array $owner, string $period, array $values, array $context): int
    {
        $values = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $values);

        return (int) DB::table('economic_assessments')->insertGetId([...$owner, 'period' => $period, 'reference_date' => $values['reference_date'] ?? today()->toDateString(),
            ...collect($values)->except(['reference_date', 'income_source_ids'])->all(),
            'income_source_ids' => ! empty($values['income_source_ids']) ? json_encode(array_values(array_map('intval', $values['income_source_ids']))) : null,
            'source' => $values['source'] ?? 'self_reported', 'verification' => 'reported', 'application_id' => $context['application']->id,
            'assessed_by' => $context['actor']->id, 'created_at' => now()]);
    }

    private function support(array $owner, string $kind, array $row, array $context): void
    {
        DB::table('external_support_records')->insert([...$owner, 'kind' => $kind, 'organization' => mb_substr((string) $row['organization'], 0, 200),
            'support_type_value_id' => $row['support_type_value_id'] ?? null, 'support_type_note' => $row['support_type_note'] ?? null, 'frequency_value_id' => $row['frequency_value_id'] ?? null,
            'regularity' => $row['regularity'] ?? null, 'received_on' => $row['received_on'] ?? null, 'starts_on' => $row['starts_on'] ?? null, 'ends_on' => $row['ends_on'] ?? null,
            'amount' => filled($row['amount'] ?? null) ? $row['amount'] : null, 'currency' => filled($row['amount'] ?? null) ? ($row['currency'] ?? null) : null, 'quantity' => $row['quantity'] ?? null,
            'evidence_note' => $row['note'] ?? ($row['evidence_note'] ?? null), 'source' => 'self_reported', 'verification' => 'reported', 'application_id' => $context['application']->id,
            'created_by' => $context['actor']->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function member(int $household, int $personId, ?int $relation, User $actor, array $flags = []): void
    {
        if (DB::table('household_members')->where('household_id', $household)->where('person_id', $personId)->whereNull('ends_on')->exists()) {
            return;
        }
        DB::table('household_members')->insert(['household_id' => $household, 'person_id' => $personId, 'relation_value_id' => $relation,
            'starts_on' => $flags['starts_on'] ?? null, 'is_studying' => $flags['is_studying'] ?? null, 'is_employed' => $flags['is_employed'] ?? null, 'is_dependent' => $flags['is_dependent'] ?? null,
            'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Request attachments move to the person/household/relationship they belong to; the request link is kept. */
    private function moveDocuments(object $application, array $map, string $target, ?int $personId, ?int $guardianshipId = null, ?int $householdId = null): void
    {
        $docs = DB::table('registry_documents')->where('application_id', $application->id)->where('target', $target);
        if ($guardianshipId) {
            DB::table('registry_documents')->where('application_id', $application->id)->where('target', $target)
                ->whereIn('document_type_value_id', DB::table('master_data_values')->where('code', 'guardianship_order')->select('id'))->update(['guardianship_id' => $guardianshipId, 'updated_at' => now()]);
        }
        $docs->whereNull('person_id')->whereNull('household_id')->update(array_filter(['person_id' => $personId, 'household_id' => $householdId]) + ['updated_at' => now()]);
    }

    private function kinship(string $code): ?int
    {
        static $ids = [];

        return $ids[$code] ??= DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->where('t.code', 'kinship_types')->where('v.code', $code)->value('v.id');
    }

    private static function bool(mixed $value): ?bool
    {
        return match ($value) {
            'yes', true, 1, '1' => true, 'no', false, 0, '0' => false, default => null
        };
    }
}
