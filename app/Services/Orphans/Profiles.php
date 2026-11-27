<?php

namespace App\Services\Orphans;

use App\Models\Orphan;
use App\Models\OrphanGuardian;
use App\Models\OrphanGuardianship;
use App\Models\Person;
use App\Models\User;
use App\Services\Orphans\CaseStudy\CaseStudies;
use Illuminate\Support\Facades\DB;

/**
 * Payloads of the orphan file, the guardian file and the side previews. Identity numbers are masked unless the
 * viewer holds «identity» and explicitly reveals one (logged); clinical details only with «health».
 */
final class Profiles
{
    public function __construct(private readonly OrphanAccess $access) {}

    public function person(Person $person, User $viewer): array
    {
        $labels = $this->labels([$person->id_type_value_id, $person->nationality_value_id]);

        return [
            'public_id' => $person->public_id, 'full_name' => $person->full_name, 'first_name' => $person->first_name, 'father_name' => $person->father_name,
            'grandfather_name' => $person->grandfather_name, 'family_name' => $person->family_name, 'extra_names' => $person->extra_names, 'name_en' => $person->name_en,
            'id_type_value_id' => $person->id_type_value_id, 'id_type' => $labels[$person->id_type_value_id] ?? null,
            'id_masked' => $person->maskedId(), 'id_format' => $person->id_format, 'id_format_label' => Identity::FORMAT_LABELS[$person->id_format] ?? $person->id_format,
            'id_verified' => $person->id_verified_at !== null, 'id_verified_at' => $person->id_verified_at?->toDateTimeString(), 'id_verification_source' => $person->id_verification_source,
            'sex' => $person->sex, 'sex_label' => Persons::SEX_LABELS[$person->sex] ?? 'غير محدد',
            'date_of_birth' => $person->date_of_birth?->toDateString(), 'dob_status' => $person->dob_status, 'age' => $person->age(),
            'nationality_value_id' => $person->nationality_value_id, 'nationality' => $labels[$person->nationality_value_id] ?? null, 'place_of_birth' => $person->place_of_birth,
            'vital_status' => $person->vital_status, 'vital_label' => Persons::VITAL_LABELS[$person->vital_status] ?? 'غير محدد',
            'phone' => $person->phone, 'alt_phone' => $person->alt_phone, 'email' => $person->email, 'notes' => $person->notes,
            'updated_at' => $person->updated_at?->toDateTimeString(),
        ];
    }

    public function orphan(Orphan $orphan, User $viewer): array
    {
        $person = $orphan->person;
        $this->access->logRead($viewer, 'orphan', $orphan->id);
        $parents = DB::table('orphan_parent_links')->where('orphan_id', $orphan->id)->get()->keyBy('relation');

        return [
            'file' => [
                'public_id' => $orphan->public_id, 'reference_no' => $orphan->reference_no, 'status' => $orphan->status, 'status_label' => RegistryQueries::ORPHAN_STATUS[$orphan->status] ?? $orphan->status,
                'status_reason' => $orphan->status_reason, 'registration_source' => $orphan->registration_source, 'registered_on' => $orphan->registered_on?->toDateString(),
                'orphan_class_value_id' => $orphan->orphan_class_value_id, 'orphan_class' => $this->labels([$orphan->orphan_class_value_id])[$orphan->orphan_class_value_id] ?? null,
                'class_basis' => $orphan->class_basis, 'completeness' => $orphan->completeness, 'sections' => $orphan->completeness_sections ?? [],
                'assigned_to' => $orphan->assigned_to, 'assignee' => $orphan->assigned_to ? DB::table('users')->where('id', $orphan->assigned_to)->value('name') : null,
                'review_due_on' => $orphan->review_due_on?->toDateString(), 'notes' => $orphan->notes,
                'restricted_notes' => $this->access->allows($viewer, 'health') ? $orphan->restricted_notes : null,
            ],
            'person' => $this->person($person, $viewer),
            'guardianships' => $this->guardianships(['orphan_id' => $orphan->id]),
            'parents' => collect(['father', 'mother'])->mapWithKeys(fn (string $relation): array => [$relation => isset($parents[$relation])
                ? $this->parent((int) $parents[$relation]->person_id, $parents[$relation], $orphan->id, $viewer) : null])->all(),
            'households' => $this->households($person->id),
            'addresses' => $this->addresses(['person_id' => $person->id]),
            'education' => DB::table('education_records as e')->leftJoin('master_data_values as s', 's.id', '=', 'e.stage_value_id')->leftJoin('master_data_values as st', 'st.id', '=', 'e.status_value_id')
                ->where('e.orphan_id', $orphan->id)->orderByDesc('e.academic_year')->orderByDesc('e.id')
                ->get(['e.*', 's.name_ar as stage', 'st.name_ar as status_name'])->map(fn ($e) => (array) $e)->all(),
            'care' => $this->care($person->id, $viewer),
            'economic' => $this->economic(['person_id' => $person->id]),
            'assessments' => DB::table('social_assessments as s')->leftJoin('users as u', 'u.id', '=', 's.assigned_to')->leftJoin('master_data_values as n', 'n.id', '=', 's.needs_level_value_id')
                ->leftJoin('users as cb', 'cb.id', '=', 's.completed_by')
                ->where('s.orphan_id', $orphan->id)->orderByDesc('s.id')->get(['s.*', 'u.name as assignee', 'n.name_ar as needs_level', 'cb.name as completed_name'])
                // Mirrors RegistryController::completeAssessment (assignee, else who scheduled it, or the head) and ::reviewAssessment.
                ->map(fn ($s) => [...(array) $s, 'can_complete' => $this->access->canDecide($viewer) || (int) ($s->assigned_to ?? $s->created_by) === (int) $viewer->id,
                    'can_review' => property_exists($s, 'review_outcome') && $s->review_outcome === null && $s->status === 'completed'
                        && $this->access->canDecide($viewer) && (int) $s->completed_by !== (int) $viewer->id])->all(),
            'documents' => $this->documents(['person_id' => $person->id], $viewer),
            'status_events' => $this->statusEvents($person->id, $viewer),
            'siblings' => $this->siblings($orphan),
            'history' => $this->history('orphans', $orphan->id),
            'modules' => array_values(array_filter(array_map(fn ($extension) => $extension->orphanSection($orphan, $viewer), iterator_to_array(app()->tagged('orphans.profile_sections'))))),
        ];
    }

    public function guardian(OrphanGuardian $guardian, User $viewer): array
    {
        $this->access->logRead($viewer, 'guardian', $guardian->id);
        $person = $guardian->person;
        $account = $guardian->portal_account_id ? DB::table('guardians')->where('id', $guardian->portal_account_id)->first(['id', 'phone', 'is_active', 'created_at']) : null;

        return [
            'guardian' => ['public_id' => $guardian->public_id, 'reference_no' => $guardian->reference_no, 'status' => $guardian->status, 'notes' => $guardian->notes,
                'portal' => $account ? ['phone' => $account->phone, 'is_active' => (bool) $account->is_active, 'created_at' => $account->created_at, ...$this->portalStatus((int) $account->id)] : null],
            'person' => $this->person($person, $viewer),
            'children' => $this->guardianships(['guardian_id' => $guardian->id], true),
            'households' => $this->households($person->id),
            'addresses' => $this->addresses(['person_id' => $person->id]),
            'economic' => $this->economic(['person_id' => $person->id]),
            'care' => $this->care($person->id, $viewer),
            'documents' => $this->documents(['person_id' => $person->id], $viewer),
            'history' => $this->history('orphan_guardians', $guardian->id),
            'modules' => array_values(array_filter(array_map(fn ($extension) => $extension->guardianSection($guardian, $viewer), iterator_to_array(app()->tagged('orphans.profile_sections'))))),
        ];
    }

    /** Side preview of a list row; no full ID, no clinical data. */
    public function preview(string $type, string $publicId, User $viewer): array
    {
        if ($type === 'guardian') {
            $guardian = OrphanGuardian::query()->with('person')->where('public_id', $publicId)->firstOrFail();
            $person = $guardian->person;
            $this->access->logRead($viewer, 'guardian', $guardian->id, 'previewed');

            return ['type' => 'guardian', 'public_id' => $guardian->public_id, 'reference_no' => $guardian->reference_no, 'full_name' => $person->full_name,
                'name_parts' => array_values(array_filter([$person->first_name, $person->father_name, $person->grandfather_name, $person->family_name])),
                'id_masked' => $person->maskedId(), 'id_format_label' => Identity::FORMAT_LABELS[$person->id_format] ?? '', 'phone' => $person->phone, 'alt_phone' => $person->alt_phone,
                'status' => $guardian->status, 'notes' => $guardian->notes, 'portal' => $guardian->portal_account_id !== null,
                'addresses' => $this->addresses(['person_id' => $person->id], true), 'children' => $this->guardianships(['guardian_id' => $guardian->id], true),
                'benefits' => $this->benefitSummary('guardian', $guardian->id)];
        }
        $orphan = Orphan::query()->with('person')->where('public_id', $publicId)->firstOrFail();
        $person = $orphan->person;
        $this->access->logRead($viewer, 'orphan', $orphan->id, 'previewed');

        return ['type' => 'orphan', 'public_id' => $orphan->public_id, 'reference_no' => $orphan->reference_no, 'full_name' => $person->full_name,
            'id_masked' => $person->maskedId(), 'id_format_label' => Identity::FORMAT_LABELS[$person->id_format] ?? '', 'sex_label' => Persons::SEX_LABELS[$person->sex] ?? 'غير محدد',
            'date_of_birth' => $person->date_of_birth?->toDateString(), 'age' => $person->age(), 'status_label' => RegistryQueries::ORPHAN_STATUS[$orphan->status] ?? $orphan->status,
            'completeness' => $orphan->completeness, 'sections' => $orphan->completeness_sections ?? [], 'addresses' => $this->addresses(['person_id' => $person->id], true),
            'guardians' => $this->guardianships(['orphan_id' => $orphan->id]), 'benefits' => $this->benefitSummary('orphan', $orphan->id)];
    }

    /** Child↔guardian links with their own statuses; the other side's name depends on the viewpoint. */
    public function guardianships(array $where, bool $fromGuardian = false): array
    {
        $query = DB::table('orphan_guardianships as g')->join('orphans as o', 'o.id', '=', 'g.orphan_id')->join('persons as op', 'op.id', '=', 'o.person_id')
            ->join('orphan_guardians as gg', 'gg.id', '=', 'g.guardian_id')->join('persons as gp', 'gp.id', '=', 'gg.person_id')
            ->leftJoin('master_data_values as k', 'k.id', '=', 'g.kinship_value_id')->leftJoin('master_data_values as ak', 'ak.id', '=', 'g.authority_kind_value_id')
            ->leftJoin('users as du', 'du.id', '=', 'g.decided_by');
        foreach ($where as $column => $value) {
            $query->where('g.'.$column, $value);
        }

        return $query->orderByRaw("CASE g.status WHEN 'approved' THEN 0 WHEN 'pending' THEN 1 WHEN 'claimed' THEN 2 ELSE 3 END")->orderByDesc('g.is_primary')->orderByDesc('g.id')
            ->get(['g.id', 'g.status', 'g.is_primary', 'g.starts_on', 'g.ends_on', 'g.legal_basis', 'g.authority_scope', 'g.source', 'g.evidence_note', 'g.decided_at', 'g.decision_note',
                'g.ended_reason', 'g.ended_at', 'g.replaces_id', 'g.kinship_value_id', 'g.authority_kind_value_id', 'k.name_ar as kinship', 'ak.name_ar as authority_kind', 'du.name as decided_by_name',
                'o.public_id as orphan_public_id', 'o.reference_no as orphan_reference', 'op.full_name as orphan_name', 'op.date_of_birth as orphan_dob',
                'gg.public_id as guardian_public_id', 'gg.reference_no as guardian_reference', 'gp.full_name as guardian_name'])
            ->map(function ($row) use ($fromGuardian): array {
                $today = today()->toDateString();
                $effective = $row->status === 'approved' && (! $row->starts_on || $row->starts_on <= $today) && (! $row->ends_on || $row->ends_on >= $today);

                return [...(array) $row, 'status_label' => OrphanGuardianship::STATUS_LABELS[$row->status] ?? $row->status, 'effective' => $effective,
                    'expired' => $row->status === 'approved' && $row->ends_on && $row->ends_on < $today, 'from_guardian' => $fromGuardian];
            })->all();
    }

    public function addresses(array $owner, bool $currentOnly = false): array
    {
        $query = DB::table('registry_addresses as a')->leftJoin('master_data_values as g', 'g.id', '=', 'a.governorate_value_id')->leftJoin('master_data_values as l', 'l.id', '=', 'a.locality_value_id')
            ->leftJoin('master_data_values as ar', 'ar.id', '=', 'a.area_value_id')->leftJoin('master_data_values as s', 's.id', '=', 'a.shelter_type_value_id')->leftJoin('master_data_values as t', 't.id', '=', 'a.tenure_value_id');
        foreach ($owner as $column => $value) {
            $query->where('a.'.$column, $value);
        }
        if ($currentOnly) {
            $query->whereNull('a.ends_on');
        }

        return $query->orderByRaw("CASE a.kind WHEN 'current' THEN 0 WHEN 'displacement' THEN 1 ELSE 2 END")->orderByDesc('a.id')
            ->get(['a.id', 'a.kind', 'a.address_text', 'a.stability', 'a.services_access', 'a.starts_on', 'a.ends_on', 'a.verified_at', 'a.governorate_value_id', 'a.locality_value_id',
                'g.name_ar as governorate', 'l.name_ar as locality', 'ar.name_ar as area', 's.name_ar as shelter', 't.name_ar as tenure'])
            ->map(fn ($a): array => [...(array) $a, 'kind_label' => ['current' => 'العنوان الحالي', 'original' => 'العنوان الأصلي', 'displacement' => 'نزوح'][$a->kind] ?? $a->kind,
                'label' => implode(' — ', array_filter([$a->governorate, $a->locality, $a->area])) ?: ($a->address_text ?: 'غير متوفر')])->all();
    }

    private function parent(int $personId, object $link, int $orphanId, User $viewer): array
    {
        $person = Person::findOrFail($personId);

        return [...$this->person($person, $viewer), 'link_basis' => $link->basis, 'link_verification' => $link->verification,
            'siblings_count' => DB::table('orphan_parent_links')->where('person_id', $personId)->where('orphan_id', '<>', $orphanId)->count(),
            'is_guardian' => DB::table('orphan_guardians as gg')->join('orphan_guardianships as g', 'g.guardian_id', '=', 'gg.id')->where('gg.person_id', $personId)
                ->where('g.orphan_id', $orphanId)->whereIn('g.status', OrphanGuardianship::LIVE)->exists(),
            'events' => $this->statusEvents($personId, $viewer), 'economic' => $this->economic(['person_id' => $personId])];
    }

    private function households(int $personId): array
    {
        return DB::table('household_members as m')->join('households as h', 'h.id', '=', 'm.household_id')->leftJoin('persons as c', 'c.id', '=', 'h.contact_person_id')
            ->leftJoin('master_data_values as r', 'r.id', '=', 'm.relation_value_id')->where('m.person_id', $personId)->orderByRaw('m.ends_on IS NOT NULL')->orderByDesc('m.id')
            ->get(['h.id', 'h.public_id', 'h.reference_no', 'h.members_count', 'h.dependents_count', 'h.earners_count', 'h.living_conditions', 'c.full_name as contact_name',
                'r.name_ar as relation', 'm.starts_on', 'm.ends_on', 'm.id as membership_id'])
            ->map(fn ($h): array => [...(array) $h, 'members' => DB::table('household_members as m')->join('persons as p', 'p.id', '=', 'm.person_id')
                ->leftJoin('master_data_values as r', 'r.id', '=', 'm.relation_value_id')->where('m.household_id', $h->id)->whereNull('m.ends_on')
                ->get(['p.public_id', 'p.full_name', 'r.name_ar as relation'])->map(fn ($m) => (array) $m)->all(),
                'addresses' => $this->addresses(['household_id' => $h->id], true), 'economic' => $this->economic(['household_id' => $h->id]),
                'case_study' => CaseStudies::STATUS[DB::table('household_case_studies')->where('household_id', $h->id)->orderByDesc('id')->value('status') ?? ''] ?? null])->all();
    }

    private function care(int $personId, User $viewer): array
    {
        $clinical = $this->access->allows($viewer, 'health');
        if ($clinical) {
            $this->access->logRead($viewer, 'person_care', $personId);
        }

        return DB::table('care_records as c')->leftJoin('master_data_values as v', 'v.id', '=', 'c.need_value_id')->leftJoin('users as u', 'u.id', '=', 'c.recorded_by')
            ->where('c.person_id', $personId)->orderByDesc('c.is_active')->orderByDesc('c.id')
            ->get(['c.*', 'v.name_ar as need', 'u.name as recorded_by_name'])
            ->map(fn ($c): array => [...collect((array) $c)->except('clinical_details')->all(), 'has_clinical' => $c->clinical_details !== null,
                'clinical_details' => $clinical && $c->clinical_details ? decrypt($c->clinical_details) : null])->all();
    }

    private function economic(array $owner): array
    {
        $query = DB::table('economic_assessments as e')->leftJoin('master_data_values as es', 'es.id', '=', 'e.employment_status_value_id')
            ->leftJoin('master_data_values as ib', 'ib.id', '=', 'e.income_band_value_id')->leftJoin('master_data_values as src', 'src.id', '=', 'e.income_source_value_id')
            ->leftJoin('master_data_values as ec', 'ec.id', '=', 'e.economic_status_value_id')->leftJoin('users as u', 'u.id', '=', 'e.assessed_by');
        foreach ($owner as $column => $value) {
            $query->where('e.'.$column, $value);
        }

        return $query->orderByDesc('e.id')->get(['e.*', 'es.name_ar as employment_status', 'ib.name_ar as income_band', 'src.name_ar as income_source', 'ec.name_ar as economic_status', 'u.name as assessed_by_name'])
            ->map(fn ($e): array => [...(array) $e, 'period_label' => $e->period === 'pre_death' ? 'قبل الوفاة (تاريخي)' : 'الوضع الحالي'])->all();
    }

    private function documents(array $owner, User $viewer): array
    {
        $restricted = $this->access->allows($viewer, 'health');
        $query = DB::table('registry_documents as d')->leftJoin('master_data_values as t', 't.id', '=', 'd.document_type_value_id');
        foreach ($owner as $column => $value) {
            $query->where('d.'.$column, $value);
        }

        return $query->when(! $restricted, fn ($q) => $q->where('d.is_restricted', false))->orderByDesc('d.id')
            ->get(['d.public_id', 'd.title', 'd.original_name', 'd.mime', 'd.size', 'd.issued_on', 'd.expires_on', 'd.review_status', 'd.is_restricted', 'd.created_at', 't.name_ar as type'])
            ->map(fn ($d): array => [...(array) $d, 'expired' => $d->expires_on && $d->expires_on < today()->toDateString()])->all();
    }

    private function statusEvents(int $personId, User $viewer): array
    {
        $restricted = $this->access->allows($viewer, 'health');

        return DB::table('person_status_events as e')->leftJoin('users as u', 'u.id', '=', 'e.recorded_by')->where('e.person_id', $personId)->orderByDesc('e.id')
            ->get(['e.id', 'e.status', 'e.event_date', 'e.place', 'e.cause', 'e.evidence_note', 'e.verification', 'e.created_at', 'u.name as recorded_by_name'])
            ->map(fn ($e): array => [...(array) $e, 'status_label' => Persons::VITAL_LABELS[$e->status] ?? $e->status,
                'cause' => $restricted && $e->cause ? decrypt($e->cause) : null, 'has_cause' => $e->cause !== null])->all();
    }

    /** Other orphans sharing a parent: siblings link to the same father/mother person rows. */
    private function siblings(Orphan $orphan): array
    {
        $parents = DB::table('orphan_parent_links')->where('orphan_id', $orphan->id)->pluck('person_id');
        if ($parents->isEmpty()) {
            return [];
        }

        return DB::table('orphan_parent_links as l')->join('orphans as o', 'o.id', '=', 'l.orphan_id')->join('persons as p', 'p.id', '=', 'o.person_id')
            ->whereIn('l.person_id', $parents)->where('l.orphan_id', '<>', $orphan->id)->distinct()
            ->get(['o.public_id', 'o.reference_no', 'p.full_name', 'p.date_of_birth'])->map(fn ($s) => (array) $s)->all();
    }

    private function history(string $table, int $id): array
    {
        return DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->where('a.subject_type', $table)->where('a.subject_id', (string) $id)
            ->orderByDesc('a.id')->limit(50)->get(['a.action', 'a.created_at', 'u.name as actor'])->map(fn ($h) => (array) $h)->all();
    }

    /** Confirmed benefits only (the benefits module fills this once installed). */
    private function benefitSummary(string $type, int $id): ?array
    {
        foreach (app()->tagged('orphans.benefit_summaries') as $extension) {
            return $extension->summary($type, $id);
        }

        return null;
    }

    public function portalStatus(int $accountId): array
    {
        $row = DB::table('guardians')->where('id', $accountId)->first();
        $status = $row->account_status ?? null;

        return ['account_status' => $status, 'account_label' => ['pending' => 'بانتظار الاعتماد', 'approved' => 'معتمد', 'rejected' => 'مرفوض', 'suspended' => 'موقوف'][$status] ?? 'غير محدد'];
    }

    /** @param list<?int> $ids @return array<int, string> */
    private function labels(array $ids): array
    {
        $ids = array_values(array_filter($ids));

        return $ids === [] ? [] : DB::table('master_data_values')->whereIn('id', $ids)->pluck('name_ar', 'id')->all();
    }
}
