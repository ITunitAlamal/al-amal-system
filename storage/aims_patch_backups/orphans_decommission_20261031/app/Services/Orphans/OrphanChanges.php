<?php

namespace App\Services\Orphans;

use App\Models\Guardian;
use App\Models\OrphanChild;
use App\Models\OrphanHousehold;
use App\Models\OrphanParent;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Change requests on approved files. The request keeps the value before and after; nothing is written to the file
 * until the executive director's approval (or a written delegate's) takes effect, and the file's history keeps
 * both. Suspension, closure and reopening are file-status requests: reversible and never a deletion.
 */
final class OrphanChanges
{
    /** category => subject type => changeable fields */
    public const FIELDS = [
        'contact' => ['guardian' => ['phone', 'alt_phone', 'email'], 'child' => ['phone']],
        'residence' => ['household' => ['current_address', 'displaced', 'displaced_since', 'shelter_type_value_id', 'current_tenure_value_id', 'rent_amount']],
        'family' => ['household' => ['family_size', 'dependents_count', 'working_members_count', 'income_source_value_id', 'monthly_income',
            'economic_status_value_id', 'needs_level_value_id', 'basic_needs']],
        'education' => ['child' => ['education']],
        'health' => ['child' => ['health']],
        'identity' => ['child' => ['first_name', 'father_name', 'grandfather_name', 'family_name', 'english_name', 'identity_type_value_id', 'identity_no',
            'date_of_birth', 'sex', 'place_of_birth'], 'father' => ['full_name', 'identity_type_value_id', 'identity_no', 'date_of_birth'],
            'mother' => ['full_name', 'identity_type_value_id', 'identity_no', 'date_of_birth', 'marital_status_value_id', 'education_level_value_id', 'specialization']],
        'parent_death' => ['father' => ['vital_status_value_id', 'death_date', 'death_cause_value_id'], 'mother' => ['vital_status_value_id', 'death_date', 'death_cause_value_id']],
        'guardianship' => ['household' => ['primary_guardian_id', 'guardian_authority_value_id']],
        'eligibility' => ['child' => ['orphan_class_value_id', 'sole_survivor']],
        'file_status' => ['child' => ['status']],
    ];

    public const CATEGORY_LABELS = [
        'contact' => 'بيانات التواصل', 'residence' => 'السكن والنزوح', 'family' => 'الوضع الأسري والاقتصادي', 'education' => 'التعليم', 'health' => 'الصحة',
        'identity' => 'بيانات الهوية', 'parent_death' => 'وفاة أحد الوالدين', 'guardianship' => 'الوصاية والتمثيل', 'eligibility' => 'تصنيف اليتم والأهلية',
        'file_status' => 'حالة الملف (إيقاف/إغلاق/إعادة فتح)',
    ];

    public const STATUS_LABELS = ['submitted' => 'مقدم', 'in_review' => 'قيد المراجعة والقرار', 'returned' => 'معاد للاستكمال', 'applied' => 'معتمد ومطبق',
        'rejected' => 'مرفوض', 'withdrawn' => 'مسحوب'];

    private const FILE_STATUS_MOVES = ['active' => ['suspended', 'closed'], 'suspended' => ['active', 'closed'], 'closed' => ['active']];

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly OrphanForms $forms,
        private readonly OrphanRegistry $registry,
        private readonly AuditService $audit,
    ) {}

    /**
     * Records a change request from staff (who then starts the review) or from the guardian portal.
     *
     * @param  array<string, mixed>  $after
     */
    public function create(User|Guardian $by, string $category, string $subjectType, int $subjectId, array $after, string $reason, array $evidence = []): int
    {
        $fields = self::FIELDS[$category][$subjectType] ?? null;
        if ($fields === null) {
            throw ValidationException::withMessages(['category' => 'نوع التعديل لا ينطبق على هذا السجل.']);
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'اذكر سبب التعديل.']);
        }
        $after = Arr::only($after, $fields);
        if ($after === []) {
            throw ValidationException::withMessages(['changes' => 'لم يُحدَّد أي تعديل.']);
        }
        [$householdId, $before] = $this->snapshot($subjectType, $subjectId, array_keys($after));
        if ($by instanceof User) {
            $this->access->assert($by, 'records');
        } else {
            abort_unless($this->guardianRepresents($by, $householdId), 403);
            abort_unless(in_array($category, ['contact', 'residence', 'family', 'education'], true), 403, 'هذا التعديل يقدمه القسم بعد التحقق من المستندات.');
        }
        $this->validateAfter($category, $subjectType, $subjectId, $after);
        $changes = [];
        foreach ($after as $field => $value) {
            if (($before[$field] ?? null) == $value && ! in_array($field, ['education', 'health', 'current_address'], true)) {
                continue;
            }
            $changes[$field] = ['before' => $before[$field] ?? null, 'after' => $value];
        }
        if ($changes === []) {
            throw ValidationException::withMessages(['changes' => 'القيم المطلوبة مطابقة للملف الحالي.']);
        }

        return DB::transaction(function () use ($by, $category, $subjectType, $subjectId, $householdId, $changes, $reason, $evidence): int {
            if (DB::table('orphan_change_requests')->where('subject_type', $subjectType)->where('subject_id', $subjectId)->where('category', $category)
                ->whereIn('status', ['submitted', 'in_review', 'returned'])->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['category' => 'يوجد طلب تعديل مفتوح من النوع نفسه على هذا السجل.']);
            }
            $id = DB::table('orphan_change_requests')->insertGetId([
                'public_id' => (string) Str::ulid(), 'reference_no' => $this->registry->number('orphan_change_ref_seq', 'CHG-'),
                'subject_type' => $subjectType, 'subject_id' => $subjectId, 'household_id' => $householdId, 'category' => $category,
                'changes' => Crypt::encryptString(json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'reason' => $reason,
                'evidence_document_ids' => $evidence ? json_encode(array_values(array_map('intval', $evidence))) : null, 'status' => 'submitted',
                'requested_by' => $by instanceof User ? $by->id : null, 'requested_by_guardian' => $by instanceof Guardian ? $by->id : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->recordRow('orphans.change.created', 'orphan_change_requests', $id, null, ['category' => $category, 'fields' => array_keys($changes)],
                $by instanceof User ? $by->id : null, request()?->ip());

            return $id;
        });
    }

    /** Staff verification done: the request goes to the head, then the executive director. */
    public function start(User $actor, int $requestId, WorkflowEngine $engine): void
    {
        $this->access->assert($actor, 'verify');
        DB::transaction(function () use ($actor, $requestId, $engine): void {
            $request = DB::table('orphan_change_requests')->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($request, 404);
            if ($request->status !== 'submitted' || $request->workflow_instance_id) {
                throw ValidationException::withMessages(['request' => 'الطلب ليس بانتظار بدء المراجعة.']);
            }
            $instance = $engine->startForSubject($actor, 'ORPHAN_CHANGE', OrphanChangeWorkflow::SUBJECT_TYPE, (int) $request->id, $this->access->departmentId());
            DB::table('orphan_change_requests')->where('id', $request->id)->update(['status' => 'in_review', 'handled_by' => $actor->id,
                'workflow_instance_id' => $instance->id, 'updated_at' => now()]);
        });
    }

    /** @return array<string, array{before: mixed, after: mixed}> */
    public function changes(object $request): array
    {
        return json_decode(Crypt::decryptString($request->changes), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Applies an approved request inside the workflow move; the current values must still match the recorded "before". */
    public function apply(object $request, int $actorId): void
    {
        $changes = $this->changes($request);
        $after = array_map(fn (array $change) => $change['after'], $changes);
        [, $current] = $this->snapshot($request->subject_type, (int) $request->subject_id, array_keys($after));
        foreach ($changes as $field => $change) {
            if (! in_array($field, ['education', 'health', 'current_address'], true) && ($current[$field] ?? null) != $change['before']) {
                throw ValidationException::withMessages(['request' => 'تغيرت قيمة «'.(OrphanForms::LABELS[$field] ?? $field).'» في الملف بعد تقديم الطلب؛ أعد الطلب للتحديث.']);
            }
        }
        $this->validateAfter($request->category, $request->subject_type, (int) $request->subject_id, $after);
        $actor = User::findOrFail($actorId);
        match ($request->subject_type) {
            'child' => $this->applyChild($request, $after, $actor),
            'household' => $this->applyHousehold($request, $after, $actor),
            'guardian' => Guardian::whereKey($request->subject_id)->firstOrFail()->forceFill($after)->save(),
            'father', 'mother' => $this->applyParent((int) $request->subject_id, $after),
        };
        DB::table('orphan_change_requests')->where('id', $request->id)->update(['status' => 'applied', 'applied_by' => $actorId, 'applied_at' => now(), 'updated_at' => now()]);
        $this->audit->recordRow('orphans.change.applied', 'orphan_'.($request->subject_type === 'guardian' ? 'guardians' : $request->subject_type), (int) $request->subject_id,
            ['fields' => array_keys($changes)], ['request' => $request->reference_no], $actorId, request()?->ip());
    }

    /** @param array<string, mixed> $after */
    private function applyChild(object $request, array $after, User $actor): void
    {
        $child = OrphanChild::whereKey($request->subject_id)->lockForUpdate()->firstOrFail();
        if (isset($after['education'])) {
            $this->registry->writeEducation($actor->id, $child, (array) $after['education']);

            return;
        }
        if (isset($after['health'])) {
            $this->registry->writeHealth($actor->id, $child, (array) $after['health']);

            return;
        }
        if (isset($after['status'])) {
            $child->forceFill(['status' => $after['status'], 'status_reason' => $request->reason])->save();

            return;
        }
        $names = $child->name_parts ?? [];
        foreach (['first_name', 'father_name', 'grandfather_name', 'family_name'] as $part) {
            if (array_key_exists($part, $after)) {
                $names[$part] = $after[$part];
            }
        }
        $values = Arr::except($after, ['first_name', 'father_name', 'grandfather_name', 'family_name', 'identity_no']);
        if (array_key_exists('identity_no', $after)) {
            $hash = $after['identity_no'] ? RegistryIdentity::hash((string) $after['identity_no']) : null;
            if ($hash && OrphanChild::where('national_id_hash', $hash)->whereKeyNot($child->id)->exists()) {
                throw ValidationException::withMessages(['request' => 'رقم الوثيقة الجديد مسجل لطفل آخر؛ يلزم التدقيق دون دمج.']);
            }
            $values += ['national_id' => $after['identity_no'], 'national_id_hash' => $hash];
        }
        $child->forceFill($values + ['name_parts' => $names, 'full_name' => implode(' ', array_filter($names))])->save();
    }

    /** @param array<string, mixed> $after */
    private function applyHousehold(object $request, array $after, User $actor): void
    {
        $household = OrphanHousehold::whereKey($request->subject_id)->lockForUpdate()->firstOrFail();
        if (isset($after['current_address'])) {
            $this->registry->replaceAddress($household, 'current', $this->registry->address((array) $after['current_address'], 'current_address.'), $actor);
            unset($after['current_address']);
        }
        if (array_key_exists('primary_guardian_id', $after)) {
            $today = today(config('orphans.timezone'))->toDateString();
            $children = DB::table('orphan_children')->where('household_id', $household->id)->where('status', 'active')->pluck('id');
            DB::table('orphan_guardianships')->whereIn('orphan_child_id', $children)->where('guardian_id', '<>', (int) $after['primary_guardian_id'])
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
                ->update(['ends_on' => $today, 'ended_reason' => 'نقل التمثيل بطلب '.$request->reference_no, 'ended_by' => $actor->id, 'updated_at' => now()]);
            $authority = isset($after['guardian_authority_value_id']) ? DB::table('master_data_values')->where('id', $after['guardian_authority_value_id'])->value('code') : null;
            // The approved transfer is a per-child decision recorded on each child's own link (the others were ended above).
            foreach ($children as $childId) {
                app(GuardianLinks::class)->approveFromDecision((int) $childId, (int) $after['primary_guardian_id'], $actor->id, 'change', ['orphan_change_request_id' => $request->id], $authority);
            }
        }
        $household->forceFill($after)->save();
    }

    /** @param array<string, mixed> $after */
    private function applyParent(int $parentId, array $after): void
    {
        $parent = OrphanParent::whereKey($parentId)->lockForUpdate()->firstOrFail();
        if (array_key_exists('identity_no', $after)) {
            $hash = $after['identity_no'] ? RegistryIdentity::hash((string) $after['identity_no']) : null;
            if ($hash && OrphanParent::where('identity_hash', $hash)->whereKeyNot($parent->id)->exists()) {
                throw ValidationException::withMessages(['request' => 'رقم الوثيقة الجديد مسجل لشخص آخر؛ يلزم التدقيق دون دمج.']);
            }
            $after['identity_hash'] = $hash;
        }
        $parent->forceFill($after + ['provenance' => 'staff_confirmed'])->save();
    }

    /**
     * Current values of the requested fields (decrypted for authorised screens) and the family file they belong to.
     *
     * @param  list<string>  $fields
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function snapshot(string $subjectType, int $subjectId, array $fields): array
    {
        return match ($subjectType) {
            'child' => (function () use ($subjectId, $fields): array {
                $child = OrphanChild::findOrFail($subjectId);
                $values = ($child->name_parts ?? []) + ['identity_no' => $child->national_id, 'date_of_birth' => $child->date_of_birth?->format('Y-m-d')]
                    + $child->only(['english_name', 'identity_type_value_id', 'sex', 'place_of_birth', 'phone', 'orphan_class_value_id', 'sole_survivor', 'status']);

                return [(int) $child->household_id, Arr::only($values, $fields)];
            })(),
            'household' => (function () use ($subjectId, $fields): array {
                $household = OrphanHousehold::findOrFail($subjectId);
                $values = $household->only(['family_size', 'dependents_count', 'working_members_count', 'income_source_value_id', 'economic_status_value_id',
                    'needs_level_value_id', 'shelter_type_value_id', 'current_tenure_value_id', 'displaced', 'basic_needs', 'primary_guardian_id', 'guardian_authority_value_id'])
                    + ['monthly_income' => $household->monthly_income, 'rent_amount' => $household->rent_amount,
                        'displaced_since' => $household->displaced_since?->format('Y-m-d'), 'current_address' => $this->registry->currentAddress($household->id, 'current')];

                return [(int) $household->id, Arr::only($values, $fields)];
            })(),
            'guardian' => (function () use ($subjectId, $fields): array {
                $guardian = Guardian::findOrFail($subjectId);
                $household = (int) DB::table('orphan_households')->where('primary_guardian_id', $guardian->id)->orderByDesc('id')->value('id');
                abort_unless($household > 0, 404);

                return [$household, Arr::only(['phone' => $guardian->phone, 'alt_phone' => $guardian->alt_phone, 'email' => $guardian->email], $fields)];
            })(),
            'father', 'mother' => (function () use ($subjectType, $subjectId, $fields): array {
                $parent = OrphanParent::where('role', $subjectType)->findOrFail($subjectId);
                $household = (int) DB::table('orphan_household_parents')->where('parent_id', $parent->id)->orderBy('id')->value('household_id');
                $values = $parent->only(['full_name', 'identity_type_value_id', 'vital_status_value_id', 'death_cause_value_id', 'marital_status_value_id', 'education_level_value_id', 'specialization'])
                    + ['identity_no' => $parent->identity_no, 'date_of_birth' => $parent->date_of_birth?->format('Y-m-d'), 'death_date' => $parent->death_date?->format('Y-m-d')];

                return [$household, Arr::only($values, $fields)];
            })(),
            default => abort(404),
        };
    }

    /** @param array<string, mixed> $after */
    private function validateAfter(string $category, string $subjectType, int $subjectId, array $after): void
    {
        if ($category === 'file_status') {
            $current = (string) DB::table('orphan_children')->where('id', $subjectId)->value('status');
            if (! in_array($after['status'] ?? null, self::FILE_STATUS_MOVES[$current] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'لا يصح هذا التغيير على حالة الملف الحالية.']);
            }

            return;
        }
        if (in_array($category, ['education', 'health'], true)) {
            $form = $category;
            $input = (array) ($after[$category] ?? []);
            $this->forms->validate($form, $input, $this->forms->base($form), null, $category.'.');

            return;
        }
        if ($category === 'guardianship') {
            if (! Guardian::whereKey((int) ($after['primary_guardian_id'] ?? 0))->exists()) {
                throw ValidationException::withMessages(['primary_guardian_id' => 'اختر وصيًا مسجلًا.']);
            }

            return;
        }
        if (isset($after['current_address'])) {
            $this->registry->address((array) $after['current_address'], 'current_address.');
            unset($after['current_address']);
        }
        if ($after === []) {
            return;
        }
        $form = match ($subjectType) {
            'child' => 'child', 'household' => 'household', 'guardian' => 'guardian', default => $subjectType,
        };
        $base = Arr::only($this->forms->base($form), array_keys($after));
        $rules = [];
        foreach ($base as $field => $typeRules) {
            $rules[$field] = ['nullable', ...$typeRules];
            if (isset(OrphanForms::VALUE_TYPES[$field])) {
                $rules[$field][] = $this->forms->valueOf(OrphanForms::VALUE_TYPES[$field]);
            }
        }
        validator($after, $rules, [], OrphanForms::LABELS)->validate();
        if (! empty($after['identity_no'])) {
            $typeId = $after['identity_type_value_id'] ?? DB::table($subjectType === 'child' ? 'orphan_children' : 'orphan_parents')->where('id', $subjectId)->value('identity_type_value_id');
            $this->forms->assertIdentityFormat($typeId ? (int) $typeId : null, (string) $after['identity_no']);
        }
    }

    public function guardianRepresents(Guardian $guardian, int $householdId): bool
    {
        $today = today(config('orphans.timezone'))->toDateString();

        return DB::table('orphan_guardianships as g')->join('orphan_children as c', 'c.id', '=', 'g.orphan_child_id')
            ->where('g.guardian_id', $guardian->id)->where('c.household_id', $householdId)
            ->where('g.status', 'approved')->whereNull('g.ended_reason')
            ->where(fn ($q) => $q->whereNull('g.ends_on')->orWhere('g.ends_on', '>=', $today))->exists();
    }
}
