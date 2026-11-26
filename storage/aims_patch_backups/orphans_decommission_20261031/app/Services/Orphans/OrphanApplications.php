<?php

namespace App\Services\Orphans;

use App\Models\OrphanChild;
use App\Models\OrphanHousehold;
use App\Models\OrphanParent;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * First-time activation of orphan files. One family application carries many children and each child gets its
 * own verification, head recommendation and executive decision, so one conflict never rejects the others.
 * The child's official file number is issued only when the executive director's approval takes effect.
 */
final class OrphanApplications
{
    public const STATUS_LABELS = [
        'draft' => 'مسودة', 'submitted' => 'مقدم من الوصي', 'under_review' => 'قيد المراجعة', 'needs_info' => 'بانتظار استكمال',
        'in_decision' => 'أمام رئيس القسم/المدير التنفيذي', 'approved' => 'معتمد', 'partially_approved' => 'معتمد جزئيًا',
        'rejected' => 'مرفوض', 'withdrawn' => 'مسحوب',
    ];

    public const VERIFICATION_LABELS = [
        'pending' => 'بانتظار التحقق', 'api_verified' => 'متحقق من السجل المدني', 'manual_verified' => 'متحقق بمراجعة موثقة',
        'conflict' => 'تعارض', 'incomplete' => 'ناقص',
    ];

    public const DECISION_LABELS = ['approve' => 'توصية بالاعتماد', 'reject' => 'توصية بالرفض', 'defer' => 'توصية بالتأجيل',
        'approved' => 'معتمد', 'rejected' => 'مرفوض', 'deferred' => 'مؤجل'];

    public function __construct(
        private readonly OrphanAccess $access,
        private readonly OrphanForms $forms,
        private readonly OrphanDocuments $documents,
        private readonly OrphanRegistry $registry,
        private readonly AuditService $audit,
    ) {}

    /** Opens (or returns) the department's application for a family file, with every child not yet active. */
    public function openForHousehold(User $actor, OrphanHousehold $household): int
    {
        $this->access->assert($actor, 'records');

        return DB::transaction(function () use ($actor, $household): int {
            $locked = OrphanHousehold::whereKey($household->id)->lockForUpdate()->firstOrFail();
            $open = DB::table('orphan_applications')->where('household_id', $locked->id)->whereIn('status', ['draft', 'submitted', 'under_review', 'needs_info', 'in_decision'])->first();
            $id = $open?->id ?? DB::table('orphan_applications')->insertGetId([
                'public_id' => (string) Str::ulid(), 'reference_no' => $this->registry->number('orphan_application_ref_seq', 'APP-'),
                'household_id' => $locked->id, 'source' => 'staff', 'form_version_id' => $this->forms->activeVersionId(), 'status' => 'under_review',
                'handled_by' => $actor->id, 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->syncChildren($id, $locked->id);
            if (! $open) {
                $this->audit->recordRow('orphans.application.opened', 'orphan_applications', $id, null, ['household' => $locked->reference_no], $actor->id, request()?->ip());
            }

            return $id;
        });
    }

    /**
     * A civil-registry claim verified and approved by staff becomes a family application with the verified children
     * (registry-verified) and the deceased father on file; the department completes the file and the executive
     * director decides. Runs inside the claim's review transaction.
     *
     * @param  list<OrphanChild>  $children
     */
    public function fromRegistryClaim(User $staff, object $claim, object $guardian, array $children): int
    {
        $parts = preg_split('/\s+/u', trim((string) $children[0]->full_name)) ?: [];
        $family = trim((string) end($parts));
        $household = OrphanHousehold::create(['public_id' => (string) Str::ulid(), 'reference_no' => $this->registry->number('orphan_household_ref_seq', 'FAM-'),
            'family_name' => $family !== '' ? $family : 'غير مدون', 'primary_guardian_id' => $guardian->id, 'status' => 'draft', 'created_by' => $staff->id]);
        $hash = (string) $claim->deceased_id_hash;
        $father = OrphanParent::where('identity_hash', $hash)->first() ?? OrphanParent::create([
            'role' => 'father', 'full_name' => 'يستكمله القسم (من السجل المدني)', 'identity_no' => $claim->deceased_national_id, 'identity_hash' => $hash,
            'vital_status_value_id' => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                ->where('t.code', 'vital_statuses')->where('v.code', 'deceased')->value('v.id'),
            'provenance' => 'registry', 'created_by' => $staff->id,
        ]);
        DB::table('orphan_household_parents')->insertOrIgnore(['household_id' => $household->id, 'parent_id' => $father->id, 'linked_by' => $staff->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($children as $child) {
            $child->forceFill(['household_id' => $household->id, 'father_id' => $father->id, 'created_by' => $staff->id])->save();
        }
        $id = DB::table('orphan_applications')->insertGetId([
            'public_id' => (string) Str::ulid(), 'reference_no' => $this->registry->number('orphan_application_ref_seq', 'APP-'), 'household_id' => $household->id,
            'source' => 'registry_claim', 'guardian_application_id' => $claim->id, 'guardian_id' => $guardian->id, 'form_version_id' => $this->forms->activeVersionId(),
            'status' => 'under_review', 'handled_by' => $staff->id, 'created_by' => $staff->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->syncChildren($id, $household->id);
        $this->audit->recordRow('orphans.application.from_registry', 'orphan_applications', $id, null, ['household' => $household->reference_no, 'children' => count($children)], $staff->id, request()?->ip());

        return $id;
    }

    /** Adds the family's not-yet-active children that are not on the application. */
    public function syncChildren(int $applicationId, int $householdId): void
    {
        $children = DB::table('orphan_children')->where('household_id', $householdId)->whereIn('status', ['applicant', 'pending_activation'])->pluck('id');
        foreach ($children as $childId) {
            DB::table('orphan_application_children')->insertOrIgnore(['orphan_application_id' => $applicationId, 'orphan_child_id' => $childId,
                'verification' => DB::table('orphan_children')->where('id', $childId)->value('verification_status') === 'api_verified' ? 'api_verified' : 'pending',
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Problems that stop the application from going to the head; empty when it can be submitted. @return list<string> */
    public function submissionProblems(object $application): array
    {
        $problems = [];
        $household = OrphanHousehold::findOrFail($application->household_id);
        if (! $this->registry->currentAddress($household->id, 'original') || ! $this->registry->currentAddress($household->id, 'current')) {
            $problems[] = 'يلزم العنوان الأصلي وعنوان السكن الحالي للأسرة.';
        }
        if (! $household->primary_guardian_id) {
            $problems[] = 'يلزم تسجيل الوصي أو المستلم المفوض للأسرة.';
        }
        $children = DB::table('orphan_application_children as a')->join('orphan_children as c', 'c.id', '=', 'a.orphan_child_id')
            ->where('a.orphan_application_id', $application->id)->get(['c.id', 'c.father_id', 'c.mother_id', 'c.orphan_class_value_id']);
        if ($children->isEmpty()) {
            $problems[] = 'لا يوجد أطفال في الطلب.';
        }
        foreach ($children as $child) {
            $both = ! empty(json_decode((string) DB::table('master_data_values')->where('id', $child->orphan_class_value_id)->value('metadata'), true)['both_parents']);
            if (! $child->father_id || ($both && ! $child->mother_id)) {
                $problems[] = 'يلزم ربط كل طفل بأبيه'.($both ? ' وأمه (يتيم الأبوين)' : '').' من سجل الأسرة.';
                break;
            }
        }
        // An expired document is no longer valid proof (requirements §7: an expired authorization is not used).
        $missing = collect($this->documents->checklist($household->id))->where('requirement', 'required')->whereIn('status', ['missing', 'expired']);
        foreach ($missing->take(6) as $item) {
            $problems[] = ($item['status'] === 'expired' ? 'مستند مطلوب منتهي الصلاحية: ' : 'مستند مطلوب ناقص: ')."{$item['type']} ({$item['owner']}).";
        }

        return $problems;
    }

    public function submit(User $actor, int $applicationId, WorkflowEngine $engine): void
    {
        $this->access->assert($actor, 'records');
        DB::transaction(function () use ($actor, $applicationId, $engine): void {
            $application = DB::table('orphan_applications')->where('id', $applicationId)->lockForUpdate()->first();
            abort_unless($application, 404);
            if (! in_array($application->status, ['under_review', 'submitted'], true) || $application->workflow_instance_id) {
                throw ValidationException::withMessages(['application' => 'الطلب ليس في مرحلة المراجعة لدى القسم.']);
            }
            $this->syncChildren($application->id, (int) $application->household_id);
            $problems = $this->submissionProblems($application);
            if ($problems !== []) {
                throw ValidationException::withMessages(['application' => $problems]);
            }
            $instance = $engine->startForSubject($actor, 'ORPHAN_FILE_ACTIVATION', OrphanApplicationWorkflow::SUBJECT_TYPE, (int) $application->id, $this->access->departmentId());
            DB::table('orphan_applications')->where('id', $application->id)->update(['status' => 'in_decision', 'workflow_instance_id' => $instance->id,
                'handled_by' => $actor->id, 'updated_at' => now()]);
            DB::table('orphan_households')->where('id', $application->household_id)->where('status', 'draft')->update(['status' => 'registered', 'updated_at' => now()]);
            DB::table('orphan_children')->whereIn('id', DB::table('orphan_application_children')->where('orphan_application_id', $application->id)->pluck('orphan_child_id'))
                ->where('status', 'applicant')->update(['status' => 'pending_activation', 'updated_at' => now()]);
            $this->audit->recordRow('orphans.application.submitted', 'orphan_applications', $application->id, ['status' => $application->status], ['status' => 'in_decision', 'workflow' => $instance->reference_no ?? null], $actor->id, request()?->ip());
        });
    }

    /**
     * Staff verification of one child. Manual (documented) verification exists only while the institution enables
     * it in the module settings; until then a child stays pending until the civil registry confirms it.
     */
    public function verifyChild(User $actor, int $rowId, string $verification, ?string $note): void
    {
        $this->access->assert($actor, 'verify');
        if (! in_array($verification, ['manual_verified', 'conflict', 'incomplete', 'pending'], true)) {
            throw ValidationException::withMessages(['verification' => 'نتيجة تحقق غير معروفة.']);
        }
        if ($verification === 'manual_verified' && ! DB::table('orphans_module_settings')->where('id', 1)->value('manual_evidence_review_enabled')) {
            throw ValidationException::withMessages(['verification' => 'التحقق اليدوي بالمستندات غير مفعّل بقرار المؤسسة؛ ينتظر الطفل التحقق من السجل المدني.']);
        }
        if ($verification !== 'pending' && mb_strlen(trim((string) $note)) < 5) {
            throw ValidationException::withMessages(['note' => 'وثّق ما راجعته وأساس النتيجة.']);
        }
        DB::transaction(function () use ($actor, $rowId, $verification, $note): void {
            $row = $this->lockedRow($rowId);
            $this->assertStage($row->orphan_application_id, ['staff_review', null]);
            if ($verification === 'manual_verified') {
                $pending = collect($this->documents->checklist((int) DB::table('orphan_applications')->where('id', $row->orphan_application_id)->value('household_id')))
                    ->where('requirement', 'required')->filter(fn ($item) => ($item['owner_type'] === 'child' && $item['owner_id'] === (int) $row->orphan_child_id || $item['owner_type'] !== 'child') && $item['status'] !== 'accepted');
                if ($pending->isNotEmpty()) {
                    throw ValidationException::withMessages(['verification' => 'لا يصح التحقق اليدوي قبل قبول المستندات المطلوبة: '.$pending->pluck('type')->unique()->implode('، ')]);
                }
            }
            DB::table('orphan_application_children')->where('id', $rowId)->update(['verification' => $verification, 'verification_note' => $note, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.application.child_verified', 'orphan_application_children', $rowId, ['verification' => $row->verification], ['verification' => $verification], $actor->id, request()?->ip());
        });
    }

    /** Head recommendation for one child, while the application is at the head's step. */
    public function recommend(User $actor, int $rowId, string $recommendation, ?string $note): void
    {
        abort_unless($this->access->isHead($actor), 403, 'التوصية لرئيس قسم الكفالات.');
        if (! in_array($recommendation, ['approve', 'reject', 'defer'], true)) {
            throw ValidationException::withMessages(['recommendation' => 'توصية غير معروفة.']);
        }
        if ($recommendation !== 'approve' && mb_strlen(trim((string) $note)) < 5) {
            throw ValidationException::withMessages(['note' => 'اذكر سبب التوصية.']);
        }
        DB::transaction(function () use ($actor, $rowId, $recommendation, $note): void {
            $row = $this->lockedRow($rowId);
            $this->assertStage($row->orphan_application_id, ['head_recommendation']);
            DB::table('orphan_application_children')->where('id', $rowId)->update(['recommendation' => $recommendation, 'recommendation_note' => $note, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.application.child_recommended', 'orphan_application_children', $rowId, null, ['recommendation' => $recommendation], $actor->id, request()?->ip());
        });
    }

    /** Executive decision for one child (or a delegate holding a valid written executive delegation for this step). */
    public function decideChild(User $actor, int $rowId, string $decision, ?string $note): void
    {
        if (! in_array($decision, ['approved', 'rejected', 'deferred'], true)) {
            throw ValidationException::withMessages(['decision' => 'قرار غير معروف.']);
        }
        if ($decision !== 'approved' && mb_strlen(trim((string) $note)) < 5) {
            throw ValidationException::withMessages(['note' => 'اذكر سبب القرار.']);
        }
        DB::transaction(function () use ($actor, $rowId, $decision, $note): void {
            $row = $this->lockedRow($rowId);
            $instance = $this->assertStage($row->orphan_application_id, ['ed_decision']);
            abort_unless(app(WorkflowEngine::class)->isCurrentAssignee((int) $instance->id, $actor), 403, 'القرار للمدير التنفيذي أو مفوضه المكتوب لهذه المرحلة.');
            if ($decision === 'approved') {
                $this->assertActivatable($row);
            }
            DB::table('orphan_application_children')->where('id', $rowId)->update(['decision' => $decision, 'decision_note' => $note, 'decided_by' => $actor->id, 'decided_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.application.child_decided', 'orphan_application_children', $rowId, null, ['decision' => $decision], $actor->id, request()?->ip());
        });
    }

    /** Gates a child must pass before approval: verified identity, accepted required documents, age within the policy. */
    public function assertActivatable(object $row): void
    {
        $child = OrphanChild::findOrFail($row->orphan_child_id);
        $problems = [];
        if (! in_array($row->verification, ['api_verified', 'manual_verified'], true)) {
            $problems[] = 'لم يتحقق من الطفل بعد ('.(self::VERIFICATION_LABELS[$row->verification] ?? $row->verification).').';
        }
        $maxAge = (int) DB::table('orphans_module_settings')->where('id', 1)->value('eligibility_max_age');
        if ($maxAge > 0 && $child->ageOn() >= $maxAge) {
            $problems[] = "عمر الطفل خارج الحد المعتمد في سياسة الأهلية ({$maxAge} سنة).";
        }
        $pending = collect($this->documents->checklist((int) $child->household_id))->where('requirement', 'required')
            ->filter(fn ($item) => ($item['owner_type'] !== 'child' || $item['owner_id'] === (int) $child->id) && $item['status'] !== 'accepted');
        if ($pending->isNotEmpty()) {
            $problems[] = 'مستندات مطلوبة غير مقبولة: '.$pending->pluck('type')->unique()->implode('، ').'.';
        }
        if ($problems !== []) {
            throw ValidationException::withMessages(['decision' => $problems]);
        }
    }

    /**
     * Applies the executive decisions when the approval takes effect: approved children get their file number and
     * become active with their guardianship; the others keep their reasons. Called inside the workflow move.
     *
     * @return array{approved: int, rejected: int, deferred: int}
     */
    public function applyDecisions(int $applicationId, int $actorId): array
    {
        $application = DB::table('orphan_applications')->where('id', $applicationId)->lockForUpdate()->first();
        $household = DB::table('orphan_households')->where('id', $application->household_id)->lockForUpdate()->first();
        $rows = DB::table('orphan_application_children')->where('orphan_application_id', $applicationId)->lockForUpdate()->get();
        $counts = ['approved' => 0, 'rejected' => 0, 'deferred' => 0];
        foreach ($rows as $row) {
            $decision = $row->decision ?? match ($row->recommendation) {
                'approve' => 'approved', 'reject' => 'rejected', default => 'deferred',
            };
            if ($decision === 'approved') {
                $this->assertActivatable($row);
                $fileNo = $this->registry->number('orphan_child_file_seq', 'ORP-');
                DB::table('orphan_children')->where('id', $row->orphan_child_id)->update(['status' => 'active', 'file_no' => $fileNo,
                    'verification_status' => $row->verification === 'api_verified' ? 'api_verified' : 'manual_verified',
                    'activated_at' => now(), 'activated_by' => $actorId, 'updated_at' => now()]);
                // The file decision approves THIS child's guardian link: an already approved one stays; otherwise the
                // household's guardian when the child is linked to it (or has no link yet), else its single pending claim.
                $links = DB::table('orphan_guardianships')->where('orphan_child_id', $row->orphan_child_id)->whereNull('ended_reason')->get(['guardian_id', 'status']);
                if (! $links->contains('status', 'approved')) {
                    $guardianId = $household->primary_guardian_id && ($links->isEmpty() || $links->contains('guardian_id', $household->primary_guardian_id))
                        ? (int) $household->primary_guardian_id : ($links->count() === 1 ? (int) $links->first()->guardian_id : null);
                    if ($guardianId === null) {
                        throw ValidationException::withMessages(['children' => 'لطفل في الطلب أكثر من ادعاء وصاية؛ يُعتمد الوصي من ملف الطفل قبل التفعيل.']);
                    }
                    app(GuardianLinks::class)->approveFromDecision((int) $row->orphan_child_id, $guardianId, $actorId, 'application',
                        array_filter(['guardian_application_id' => $application->guardian_application_id, 'orphan_application_id' => $applicationId]),
                        $household->guardian_authority_value_id ? DB::table('master_data_values')->where('id', $household->guardian_authority_value_id)->value('code') : null);
                }
            } else {
                DB::table('orphan_children')->where('id', $row->orphan_child_id)->where('status', 'pending_activation')
                    ->update(['status' => 'applicant', 'status_reason' => $row->decision_note ?? $row->recommendation_note, 'updated_at' => now()]);
            }
            DB::table('orphan_application_children')->where('id', $row->id)->update(['decision' => $decision, 'decided_by' => $row->decided_by ?? $actorId,
                'decided_at' => $row->decided_at ?? now(), 'updated_at' => now()]);
            $counts[$decision]++;
        }
        $status = $counts['approved'] === 0 ? 'rejected' : ($counts['approved'] === $rows->count() ? 'approved' : 'partially_approved');
        DB::table('orphan_applications')->where('id', $applicationId)->update(['status' => $status, 'decided_by' => $actorId, 'decided_at' => now(), 'updated_at' => now()]);
        if ($counts['approved'] > 0) {
            DB::table('orphan_households')->where('id', $household->id)->update(['status' => 'active', 'updated_at' => now()]);
        }

        return $counts;
    }

    private function lockedRow(int $rowId): object
    {
        $row = DB::table('orphan_application_children')->where('id', $rowId)->lockForUpdate()->first();
        abort_unless($row, 404);

        return $row;
    }

    /** @param list<?string> $steps allowed current step codes (null = before the workflow starts) */
    private function assertStage(int $applicationId, array $steps): ?object
    {
        $application = DB::table('orphan_applications')->where('id', $applicationId)->first();
        $instance = $application->workflow_instance_id ? DB::table('workflow_instances as i')->join('workflow_steps as s', 's.id', '=', 'i.current_step_id')
            ->where('i.id', $application->workflow_instance_id)->first(['i.*', 's.code as step_code']) : null;
        $current = $instance && in_array($instance->status, ['in_progress', 'returned', 'submitted'], true) ? $instance->step_code : null;
        if ($instance && ! in_array($instance->status, ['in_progress', 'returned', 'submitted'], true)) {
            throw ValidationException::withMessages(['application' => 'انتهت معاملة هذا الطلب.']);
        }
        if (! in_array($current, $steps, true)) {
            throw ValidationException::withMessages(['application' => 'الإجراء غير متاح في مرحلة الطلب الحالية.']);
        }

        return $instance;
    }
}
