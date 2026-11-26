<?php

namespace App\Services\Orphans\Registration;

use App\Models\Guardian;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Notifications\NotificationCenter;
use App\Services\Orphans\Identity;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\PortalApplications;
use App\Services\Orphans\Registry;
use App\Services\Orphans\RegistryQueries;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Family registration requests (portal or staff). One request carries the family snapshot, with independent child
 * cards. A draft can be saved partially and is invisible to reviewers. Submission signs the declaration and
 * creates one claim per child. The applicant may edit again only when asked to complete. Decided children stay
 * locked.
 */
final class FamilyRegistrations
{
    public const EDITABLE = ['draft', 'needs_completion'];

    public function __construct(private readonly PortalApplications $applications, private readonly OrphanAccess $access, private readonly AuditService $audit) {}

    /** The request snapshot (decrypted). */
    public static function form(object $application): array
    {
        if (blank($application->form_data ?? null)) {
            return [];
        }

        return json_decode(Crypt::decryptString($application->form_data), true) ?: [];
    }

    /** Health and care answers removed for readers without the «health» capability. */
    public static function withoutHealth(array $form): array
    {
        foreach ($form['children'] ?? [] as $i => $child) {
            unset($form['children'][$i]['health'], $form['children'][$i]['social']['loss_circumstances']);
        }
        unset($form['mother']['care']);

        return $form;
    }

    public function createDraft(?Guardian $account, ?User $user): object
    {
        $source = $account ? 'portal' : 'staff';
        if ($user) {
            $this->access->assertEdit($user);
        }
        if ($account) {
            abort_if(in_array($account->account_status, ['rejected', 'suspended'], true), 403, 'الحساب غير مقبول لتقديم الطلبات؛ راجع القسم.');
            abort_if(DB::table('portal_applications')->where('guardian_account_id', $account->id)->whereNotIn('status', ['completed', 'rejected', 'withdrawn'])->count() >= 3, 422,
                'لديك طلبات مفتوحة؛ أكمل مسودتك أو انتظر قرار القسم.');
        }
        $parts = $account ? (session('guardian.name_parts') ?: []) : [];
        $applicant = $account ? [...$parts, 'phone' => $account->phone] : [];
        $form = ['request' => ['applicant' => $applicant], 'guardians' => $account ? [['key' => (string) Str::uuid(), 'role' => 'applicant']] : [],
            'children' => [['key' => (string) Str::uuid(), 'resides_with_household' => true]], 'declaration' => ['truth' => false, 'consent' => false]];
        $id = DB::table('portal_applications')->insertGetId([
            'public_id' => (string) Str::ulid(), 'reference_no' => Registry::reference('APP', 'portal_application_seq'), 'source' => $source,
            'guardian_account_id' => $account?->id, 'created_by_user' => $user?->id, 'status' => 'draft', 'full_name' => $account?->name ?: 'مسودة طلب تسجيل',
            ...($account ? ['id_number' => encrypt($account->national_id), 'id_hash' => Identity::hash(Identity::clean($account->national_id)), 'id_last4' => mb_substr(Identity::clean($account->national_id), -4), 'phone' => $account->phone] : []),
            'form_data' => Crypt::encryptString(json_encode($form, JSON_UNESCAPED_UNICODE)), 'last_saved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->applications->event($id, 'draft_created', $user?->id, guardian: $account?->id);

        return DB::table('portal_applications')->find($id);
    }

    /** Portal owner, or the staff creator (the head may continue any staff draft). */
    public function assertOwner(object $application, ?Guardian $account, ?User $user): void
    {
        if ($account) {
            abort_unless($application->source === 'portal' && (int) $application->guardian_account_id === (int) $account->id, 404);

            return;
        }
        abort_unless($user && $application->source === 'staff', 404);
        $this->access->assertEdit($user);
        abort_unless((int) $application->created_by_user === (int) $user->id || $this->access->canAssign($user), 403, 'هذه المسودة لموظف آخر.');
    }

    /**
     * Saves the whole form. Submitting signs the declaration, opens or refreshes the child claims, and sends the
     * request to the review queue. Input is already validated (FamilyRegistrationRequest).
     */
    /**
     * $expectedVersion is the form version the editor loaded. A save made from an older copy (a second tab, or
     * another staff member) is refused instead of silently overwriting newer answers.
     */
    public function save(object $application, array $data, bool $submit, ?Guardian $account, ?User $user, ?int $expectedVersion = null, ?int $lastStep = null): object
    {
        $this->assertOwner($application, $account, $user);

        return DB::transaction(function () use ($application, $data, $submit, $account, $user, $expectedVersion, $lastStep): object {
            $application = DB::table('portal_applications')->where('id', $application->id)->lockForUpdate()->first();
            abort_unless(in_array($application->status, self::EDITABLE, true), 409, 'لا يمكن تعديل الطلب الآن؛ هو قيد المراجعة أو مغلق.');
            abort_if($expectedVersion !== null && $expectedVersion !== (int) $application->form_version, 409,
                'حُفظت نسخة أحدث من هذا الطلب في نافذة أو جهاز آخر. أعد تحميل الصفحة قبل المتابعة حتى لا تضيع التعديلات.');
            $previous = self::form($application);
            $mayHealth = $account !== null || $this->access->allows($user, 'health');
            if (! $mayHealth) {
                $data = $this->keepHealth($data, $previous);
            }
            if ($account) {
                $data = RegistrationForm::keepStaffOnly($data, $previous);
            }
            $this->assertLockedCards($application, $data);
            $applicant = $data['request']['applicant'] ?? [];
            $names = collect($applicant)->only(['first_name', 'father_name', 'grandfather_name', 'family_name'])->map(fn ($v) => filled($v) ? trim((string) $v) : null)->all();
            $fill = [...$names, 'full_name' => RegistrationForm::displayName($applicant) ?: ($account?->name ?? 'مسودة طلب تسجيل'),
                'applicant_relationship_value_id' => $data['request']['relationship_value_id'] ?? null, 'applicant_note' => $data['request']['notes'] ?? null,
                'alt_phone' => filled($applicant['alt_phone'] ?? null) ? Identity::clean($applicant['alt_phone']) : null,
                'form_data' => Crypt::encryptString(json_encode($data, JSON_UNESCAPED_UNICODE)), 'form_version' => (int) $application->form_version + 1, 'last_saved_at' => now(), 'updated_at' => now(),
                'last_step' => $lastStep !== null ? max(0, min(8, $lastStep)) : $application->last_step];
            if (! $account) {
                // Staff request: the applicant identity is whatever was entered (the portal one comes from the account).
                [, $clean] = Identity::classify($applicant['id_number'] ?? null, null);
                $fill += ['id_number' => $clean ? encrypt($clean) : null, 'id_hash' => $clean ? Identity::hash($clean) : null, 'id_last4' => $clean ? mb_substr($clean, -4) : null,
                    'phone' => filled($applicant['phone'] ?? null) ? Identity::clean($applicant['phone']) : null];
            }
            if ($submit) {
                $hash = $fill['id_hash'] ?? $application->id_hash;
                $fill += ['matched_guardian_id' => $application->matched_guardian_id ?? ($hash ? DB::table('orphan_guardians as gg')->join('persons as p', 'p.id', '=', 'gg.person_id')->where('p.id_hash', $hash)->value('gg.id') : null),
                    'declaration_accepted_at' => now(), 'consent_accepted_at' => now(), 'submitted_at' => $application->submitted_at ?? now(),
                    'declared_by_name' => mb_substr(trim((string) ($data['declaration']['name'] ?? '')) ?: ($fill['full_name'] ?? ''), 0, 200),
                    'status' => $application->status === 'draft' ? 'submitted' : 'resubmitted'];
            }
            DB::table('portal_applications')->where('id', $application->id)->update($fill);
            if ($submit) {
                $this->syncChildren($application, $data);
                $this->applications->event($application->id, $application->status === 'draft' ? 'submitted' : 'resubmitted', $user?->id, guardian: $account?->id,
                    payload: ['children' => count($data['children'] ?? []), 'form_version' => $fill['form_version']]);
                $this->audit->recordRow('orphans.registration.submitted', 'portal_applications', $application->id, null, ['reference' => $application->reference_no, 'source' => $application->source], $user?->id, request()?->ip());
                $this->notifyAssignee($application);
            }

            return DB::table('portal_applications')->find($application->id);
        });
    }

    /** A draft is discarded (kept as «مسحوب»); a submitted request is never deleted here. */
    public function discard(object $application, ?Guardian $account, ?User $user): void
    {
        $this->assertOwner($application, $account, $user);
        abort_unless($application->status === 'draft', 409, 'يمكن حذف المسودة فقط.');
        DB::table('portal_applications')->where('id', $application->id)->update(['status' => 'withdrawn', 'updated_at' => now()]);
        $this->applications->event($application->id, 'draft_discarded', $user?->id, guardian: $account?->id);
    }

    /** Optional initial attachment, private disk, attributed to a person/relationship/household in the request. */
    public function attach(object $application, UploadedFile $file, ?int $typeId, string $target, ?Guardian $account, ?User $user): void
    {
        $this->assertOwner($application, $account, $user);
        abort_unless(in_array($application->status, self::EDITABLE, true), 409, 'لا يمكن إرفاق مستندات بالطلب الآن.');
        $targets = self::targets(self::form($application));
        if (! isset($targets[$target])) {
            throw ValidationException::withMessages(['target' => 'اختر صاحب المستند من الطلب؛ احفظ الطلب أولًا إن أضفت شخصًا جديدًا.']);
        }
        abort_if(DB::table('registry_documents')->where('application_id', $application->id)->count() >= 40, 422, 'بلغ الطلب الحد الأعلى للمرفقات (40).');
        $code = $typeId ? DB::table('master_data_values')->where('id', $typeId)->value('code') : null;
        $sha = hash_file('sha256', $file->getRealPath());
        abort_if(DB::table('registry_documents')->where('application_id', $application->id)->where('sha256', $sha)->where('target', $target)->exists(), 422, 'هذا الملف مرفق مسبقًا لنفس الشخص.');
        DB::table('registry_documents')->insert([
            'public_id' => (string) Str::ulid(), 'application_id' => $application->id, 'target' => $target, 'document_type_value_id' => $typeId,
            'title' => $targets[$target], 'path' => $file->store('registrations/'.now()->format('Y/m'), 'local'),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(), 'sha256' => $sha,
            'is_restricted' => $code === 'medical_report', 'uploaded_by' => $user?->id, 'uploaded_by_guardian' => $account?->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->applications->event($application->id, 'document_attached', $user?->id, guardian: $account?->id, payload: ['target' => $target, 'type' => $code]);
    }

    /** Removes an attachment the applicant uploaded, before anyone reviewed it. */
    public function detach(object $application, string $documentPublicId, ?Guardian $account, ?User $user): void
    {
        $this->assertOwner($application, $account, $user);
        abort_unless(in_array($application->status, self::EDITABLE, true), 409, 'لا يمكن تعديل المرفقات الآن.');
        $doc = DB::table('registry_documents')->where('public_id', $documentPublicId)->where('application_id', $application->id)->firstOrFail();
        abort_if($doc->review_status !== 'pending' || $doc->person_id || $doc->household_id || $doc->guardianship_id, 409, 'رُوجع هذا المستند أو نُقل إلى السجل؛ لا يُحذف.');
        DB::table('registry_documents')->where('id', $doc->id)->delete();
        Storage::disk('local')->delete($doc->path);
        $this->applications->event($application->id, 'document_removed', $user?->id, guardian: $account?->id, payload: ['target' => $doc->target]);
    }

    /** What the autosave reports back: only what the server confirmed. */
    public static function savedState(object $application): array
    {
        return ['saved_at' => (string) $application->last_saved_at, 'version' => (int) $application->form_version, 'status' => $application->status];
    }

    /** Props of the registration form page (portal or staff). Health answers only for readers allowed to see them. */
    public function page(object $application, bool $mayHealth, array $urls): array
    {
        $form = self::form($application);
        $documents = self::documents($application->id);
        $children = DB::table('portal_application_children')->where('application_id', $application->id)->whereNotNull('card_key')->get(['card_key', 'status', 'decision_note'])
            ->mapWithKeys(fn ($c) => [$c->card_key => ['status' => $c->status, 'label' => PortalApplications::CHILD_STATUS[$c->status] ?? $c->status, 'note' => $c->status === 'rejected' ? $c->decision_note : null]]);

        return [
            'application' => ['public_id' => $application->public_id, 'reference_no' => $application->reference_no, 'status' => $application->status,
                'status_label' => $application->status === 'draft' ? 'مسودة' : (PortalApplications::STATUS[$application->status] ?? $application->status), 'source' => $application->source,
                'created_at' => $application->created_at, 'submitted_at' => $application->submitted_at, 'last_saved_at' => $application->last_saved_at,
                'completion_request' => $application->status === 'needs_completion' ? $application->completion_request : null,
                'editable' => in_array($application->status, self::EDITABLE, true), 'version' => (int) $application->form_version, 'last_step' => (int) ($application->last_step ?? 0)],
            'form' => (fn (array $f): array => $application->source === 'portal' ? RegistrationForm::withoutStaffOnly($f) : $f)($mayHealth ? $form : self::withoutHealth($form)),
            'documents' => array_map(fn (array $d): array => collect($d)->except(['is_restricted'])->all(), $mayHealth ? $documents : array_values(array_filter($documents, fn ($d) => ! $d['is_restricted']))),
            'missingDocuments' => RegistrationForm::missingDocuments($form, $documents),
            'targets' => self::targets($form), 'childStatus' => $children, 'canHealth' => $mayHealth, 'options' => self::options($form), 'urls' => $urls,
            'fieldRules' => RegistrationFieldSpec::forClient($application->source === 'portal' ? 'portal' : 'staff'),
        ];
    }

    /** Form list => «ثوابت النظام» type. */
    public const OPTION_TYPES = [
        'id_types' => 'identity_document_types', 'nationalities' => 'nationalities', 'kinship' => 'kinship_types', 'marital' => 'marital_statuses',
        'education_levels' => 'education_levels', 'employment' => 'employment_statuses', 'income_sources' => 'income_sources', 'economic' => 'economic_statuses',
        'death_causes' => 'death_causes', 'health' => 'health_statuses', 'disability' => 'disability_types', 'enrollment' => 'enrollment_statuses',
        'stages' => 'education_stages', 'institution_types' => 'institution_types', 'shelter' => 'shelter_types', 'tenure' => 'housing_tenures',
        'assistance' => 'assistance_types', 'sponsorship_types' => 'sponsorship_types', 'frequencies' => 'sponsorship_frequencies', 'documents' => 'orphan_document_types',
        'authority' => 'guardian_authority_kinds', 'income_bands' => 'income_bands', 'building_types' => 'building_types', 'building_conditions' => 'building_conditions',
        'furnishing_conditions' => 'furnishing_conditions', 'court_statuses' => 'guardianship_court_statuses', 'grades' => 'school_grades',
        'achievement' => 'achievement_levels', 'original_housing_types' => 'original_housing_types', 'dwelling_conditions' => 'dwelling_conditions',
    ];

    /**
     * Reference lists of the form, all from «ثوابت النظام» (codes drive the conditional sections). Only active values
     * are offered; a value deactivated after it was chosen in this request stays listed (marked) so the saved answer
     * remains readable and is not silently dropped.
     */
    public static function options(?array $form = null): array
    {
        $q = app(RegistryQueries::class);
        $lists = array_map(fn (string $type): array => $q->values($type), self::OPTION_TYPES);
        if ($form) {
            $lists = self::withRetired($lists, $form);
        }

        return $lists + [
            'currencies' => DB::table('currencies')->where('is_active', true)->orderByRaw("CASE code WHEN 'ILS' THEN 0 WHEN 'USD' THEN 1 ELSE 2 END")->get(['code', 'name_ar'])->map(fn ($c) => ['code' => $c->code, 'name' => $c->name_ar])->all(),
            'amount_status' => RegistrationForm::AMOUNT_STATUS, 'id_missing_reasons' => RegistrationForm::ID_MISSING_REASONS,
            'contact_channels' => RegistrationForm::CONTACT_CHANNELS, 'higher_education' => RegistrationForm::HIGHER_EDUCATION,
            'caregiving' => RegistrationForm::CAREGIVING, 'shelter_stability' => RegistrationForm::SHELTER_STABILITY, 'info_sources' => RegistrationForm::INFO_SOURCES,
        ];
    }

    /** Adds inactive values the saved form still refers to. */
    private static function withRetired(array $lists, array $form): array
    {
        $ids = [];
        array_walk_recursive($form, function ($value, $key) use (&$ids): void {
            if ((str_ends_with((string) $key, '_value_id') || is_int($key)) && is_numeric($value)) {
                $ids[(int) $value] = true;
            }
        });
        if ($ids === []) {
            return $lists;
        }
        $byType = array_flip(self::OPTION_TYPES);
        foreach (DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')->whereIn('v.id', array_keys($ids))->where('v.is_active', false)
            ->get(['v.id', 'v.name_ar', 'v.code', 't.code as type']) as $v) {
            if (isset($byType[$v->type])) {
                $lists[$byType[$v->type]][] = ['id' => (int) $v->id, 'name' => $v->name_ar.' (قيمة موقوفة)', 'code' => $v->code, 'retired' => true];
            }
        }

        return $lists;
    }

    public static function uploadRules(): array
    {
        return ['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png'],
            'document_type_value_id' => ['nullable', 'integer', Rule::exists('master_data_values', 'id')->where('type_id', DB::table('master_data_types')->where('code', 'orphan_document_types')->value('id'))],
            'target' => ['required', 'string', 'max:60']];
    }

    public static function uploadAttributes(): array
    {
        return ['file' => 'الملف', 'document_type_value_id' => 'نوع المستند', 'target' => 'صاحب المستند'];
    }

    /** @return list<array> attachments of the request (no paths). */
    public static function documents(int $applicationId): array
    {
        return DB::table('registry_documents as d')->leftJoin('master_data_values as t', 't.id', '=', 'd.document_type_value_id')->where('d.application_id', $applicationId)->orderBy('d.id')
            ->get(['d.public_id', 'd.target', 'd.title', 'd.original_name', 'd.size', 'd.review_status', 'd.is_restricted', 'd.created_at', 't.name_ar as type', 't.code as type_code'])
            ->map(fn ($d) => (array) $d)->all();
    }

    /** Who a document can belong to. @return array<string, string> key => Arabic label */
    public static function targets(array $form): array
    {
        $targets = ['request' => 'الطلب عامة', 'household' => 'الأسرة', 'father' => 'الأب', 'mother' => 'الأم'];
        if (RegistrationForm::hasName($form['request']['applicant'] ?? [])) {
            $targets['applicant'] = 'مقدم الطلب';
        }
        foreach ($form['guardians'] ?? [] as $i => $guardian) {
            $name = match ($guardian['role'] ?? null) {
                'mother' => 'الأم', 'applicant' => 'مقدم الطلب', default => RegistrationForm::displayName($guardian)
            };
            $targets['guardian:'.$guardian['key']] = 'الوصي '.($i + 1).($name ? ' — '.$name : '');
        }
        foreach ($form['children'] ?? [] as $i => $child) {
            $targets['child:'.$child['key']] = 'اليتيم '.($i + 1).(($name = RegistrationForm::displayName($child)) ? ' — '.$name : '');
        }

        return $targets;
    }

    /** One claim row per child card; decided claims are never rewritten, removed cards withdraw open claims. */
    private function syncChildren(object $application, array $form): void
    {
        $rows = DB::table('portal_application_children')->where('application_id', $application->id)->get()->keyBy('card_key');
        $guardians = collect($form['guardians'] ?? [])->keyBy('key');
        $keys = [];
        foreach ($form['children'] ?? [] as $child) {
            $keys[] = $child['key'];
            $row = $rows->get($child['key']);
            if ($row && ! in_array($row->status, ['pending', 'needs_completion'], true)) {
                continue;
            }
            [, $clean] = Identity::classify($child['id_number'] ?? null, filled($child['id_type_value_id'] ?? null) ? (int) $child['id_type_value_id'] : null);
            $guardian = $guardians->get($child['guardian_key'] ?? '');
            $values = [
                ...collect($child)->only(['first_name', 'father_name', 'grandfather_name', 'family_name'])->map(fn ($v) => filled($v) ? trim((string) $v) : null)->all(),
                'full_name' => RegistrationForm::displayName($child) ?: 'طفل دون اسم', 'id_number' => $clean ? encrypt($clean) : null, 'id_hash' => $clean ? Identity::hash($clean) : null,
                'id_last4' => $clean ? mb_substr($clean, -4) : null, 'date_of_birth' => $child['date_of_birth'] ?? null, 'sex' => $child['sex'] ?? null,
                'kinship_value_id' => $child['kinship_value_id'] ?? null, 'relationship_note' => mb_substr((string) ($child['guardianship']['evidence_note'] ?? ''), 0, 300) ?: null,
                'guardian_key' => $child['guardian_key'] ?? null, 'guardian_name' => $guardian ? $this->guardianName($guardian, $form) : null,
                'matched_orphan_id' => $clean ? DB::table('orphans as o')->join('persons as p', 'p.id', '=', 'o.person_id')->where('p.id_hash', Identity::hash($clean))->value('o.id') : null,
                'status' => 'pending', 'updated_at' => now(),
            ];
            $row ? DB::table('portal_application_children')->where('id', $row->id)->update($values)
                : DB::table('portal_application_children')->insert([...$values, 'application_id' => $application->id, 'card_key' => $child['key'], 'created_at' => now()]);
        }
        DB::table('portal_application_children')->where('application_id', $application->id)->whereIn('status', ['pending', 'needs_completion'])
            ->where(fn ($q) => $q->whereNull('card_key')->orWhereNotIn('card_key', $keys ?: ['-']))->update(['status' => 'withdrawn', 'updated_at' => now()]);
    }

    public function guardianName(array $guardian, array $form): string
    {
        return match ($guardian['role'] ?? null) {
            'mother' => 'الأم: '.(RegistrationForm::displayName($form['mother'] ?? []) ?: 'دون اسم'),
            'applicant' => 'مقدم الطلب: '.(RegistrationForm::displayName($form['request']['applicant'] ?? []) ?: 'دون اسم'),
            default => RegistrationForm::displayName($guardian) ?: 'وصي دون اسم',
        };
    }

    /** A decided child card cannot disappear or be rewritten by the applicant. */
    private function assertLockedCards(object $application, array $data): void
    {
        $present = array_column($data['children'] ?? [], 'key');
        $locked = DB::table('portal_application_children')->where('application_id', $application->id)->whereIn('status', ['approved', 'rejected'])->pluck('full_name', 'card_key');
        foreach ($locked as $key => $name) {
            if (! in_array($key, $present, true)) {
                throw ValidationException::withMessages(['children' => 'لا يمكن حذف «'.$name.'»؛ صدر قرار بشأنه.']);
            }
        }
    }

    /** Staff without the «health» capability can neither see nor erase the health answers. */
    private function keepHealth(array $data, array $previous): array
    {
        $old = collect($previous['children'] ?? [])->keyBy('key');
        foreach ($data['children'] ?? [] as $i => $child) {
            unset($data['children'][$i]['health'], $data['children'][$i]['social']['loss_circumstances']);
            if ($health = $old->get($child['key'])['health'] ?? null) {
                $data['children'][$i]['health'] = $health;
            }
            if (filled($loss = $old->get($child['key'])['social']['loss_circumstances'] ?? null)) {
                $data['children'][$i]['social']['loss_circumstances'] = $loss;
            }
        }
        unset($data['mother']['care']);
        if (! empty($previous['mother']['care'])) {
            $data['mother']['care'] = $previous['mother']['care'];
        }

        return $data;
    }

    private function notifyAssignee(object $application): void
    {
        if ($application->assigned_to && $application->status !== 'draft') {
            app(NotificationCenter::class)->notify((int) $application->assigned_to, 'orphans_portal_update', ['title' => 'أُعيد تقديم الطلب '.$application->reference_no,
                'url' => '/orphans/reviews/'.$application->public_id, 'dedupe' => 'registration-resubmit-'.$application->id.'-'.now()->timestamp]);
        }
    }
}
