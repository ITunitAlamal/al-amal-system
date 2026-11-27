<?php

use App\Services\Workflow\AssigneeResolver;
use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Field assignment in general services, send-backs from Stores and Purchasing in maintenance, and hand-off
 * actions that say what they are. Data only: no table changes, and no request exists yet on either workflow.
 *
 * SERVICE («طلب خدمة عامة»). General Services workers have no accounts, so a field job used to be one free-text
 * note. It becomes two recorded moves of the same request:
 * - «تسجيل تكليف ميداني» (field_assign) from "internal_execution" to the new stage "field_work", with the
 *   worker's name in the field "field_worker_name";
 * - «توثيق الإنجاز» (document_done) from "field_work" to the requester's verification, with the actual
 *   completion time in "field_completed_at" and the outcome as the action's note. Who documented it and when
 *   are the event's actor and time, as for every move. «إلغاء التكليف الميداني» (field_cancel) goes back.
 * "field_work" belongs to the same holder as "internal_execution" and carries "field_work" in its
 * assignment_config, which the dashboards read (App\Services\Workflow\StepAssignments).
 *
 * MAINTENANCE («طلب صيانة»). The storekeeper and the purchasing officer can send a request with unclear
 * requirements back to the technician («إعادة للاستكمال», action "return"); the technician who handled it gets
 * it back without a new choice.
 *
 * Both. «صرف المواد», «استلام المواد» and «صرف الأجرة» only move the request: no stock movement, receipt or
 * payment record is made by the workflow, and Accounts keep their books in the external accounting software.
 * Each of these actions now asks for the number of the document that stands behind it and is named as the
 * officer's confirmation. Nothing is written to stock, purchasing or accounting records.
 *
 * Rules of this patch:
 * - the published version is changed in place, and an open draft too, so publishing it later drops nothing;
 * - a name is changed only while it is still the installed default, so an administrator's wording is kept;
 * - an existing field definition is never overwritten;
 * - every change writes an audit row. Safe to run twice: whatever is already there is left alone.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_30_000023 (التكليف الميداني في الخدمات العامة، إعادة المخزن والمشتريات، وتسميات التسليم)';

    private const ENTITY = 'aims_request';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** @var array<string, array<string, array{0: string, 1: string}>> workflow => step code => [installed name, new name] */
    private const STEP_NAMES = [
        'SERVICE' => ['internal_execution' => ['تكليف المنفذ الداخلي وتوثيق الإنجاز', 'التنفيذ الداخلي: إسناد إلى موظف أو تكليف ميداني']],
    ];

    /** @var list<array{0: string, 1: string, 2: string, 3: string, 4: string}> workflow, from step, action, installed name, new name */
    private const TRANSITION_NAMES = [
        ['MAINTENANCE', 'verification', 'return', 'إرجاع لاستكمال الصيانة', 'إعادة للاستكمال'],
        ['MAINTENANCE', 'ext_verification', 'return', 'إرجاع لاستكمال التنفيذ الخارجي', 'إعادة للاستكمال'],
        ['SERVICE', 'verification', 'return', 'إعادة للموارد البشرية لاستكمال الخدمة', 'إعادة للاستكمال'],
        ['SERVICE', 'verification', 'accept', 'تأكيد انتهاء الخدمة ورفعه لاعتماد الإغلاق', 'تأكيد الإنجاز ورفعه لاعتماد الإغلاق'],
        ['SERVICE', 'internal_execution', 'done', 'تم التنفيذ (اذكر المنفذ والموعد والنتيجة)', 'تم التنفيذ (اكتب النتيجة)'],
    ];

    /**
     * Fields filled with one action of a step ("action_codes"): required with that action, not asked with the others.
     * Each row: workflow, step, action, field code, label, type, validation, help, sort order, and for a hand-off
     * the pair [installed action name, new name] applied once the field is in place.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: array<string, mixed>, 7: string, 8: int, 9: ?array{0: string, 1: string}}>
     */
    private const FIELDS = [
        ['SERVICE', 'internal_execution', 'field_assign', 'field_worker_name', 'العامل المكلَّف', 'text', ['max_length' => 150],
            'اسم العامل الذي كُلِّف بالعمل ميدانيًا (لا حساب له في النظام).', 30, null],
        ['SERVICE', 'field_work', 'document_done', 'field_completed_at', 'وقت الإنجاز الفعلي', 'datetime', ['not_future' => true],
            'متى انتهى العمل فعليًا، لا وقت تسجيله في النظام.', 40, null],
        ['SERVICE', 'ext_payment', 'paid', 'payment_voucher_no', 'رقم سند صرف الأجرة', 'text', ['max_length' => 60],
            'رقم سند الصرف كما صدر من الحسابات. لا يُسجَّل من هذا الطلب قيد مالي في النظام.', 50,
            ['تم صرف الأجرة وفق العقد', 'تأكيد صرف الأجرة وفق العقد (مع رقم السند)']],
        ['MAINTENANCE', 'purchase', 'received', 'materials_receipt_no', 'رقم سند استلام المواد', 'text', ['max_length' => 60],
            'رقم محضر أو سند استلام المواد المشتراة. لا يُسجَّل من هذا الطلب استلام في المخزون.', 50,
            ['استلام المواد', 'تأكيد استلام المواد (مع رقم السند)']],
        ['MAINTENANCE', 'materials', 'issue', 'stock_issue_voucher_no', 'رقم سند الصرف المخزني', 'text', ['max_length' => 60],
            'رقم سند صرف المواد من المخزن. لا تُسجَّل من هذا الطلب حركة مخزون في النظام.', 60,
            ['صرف المواد للفني', 'تأكيد صرف المواد للفني (مع رقم السند)']],
        ['MAINTENANCE', 'ext_payment', 'paid', 'payment_voucher_no', 'رقم سند صرف الأجرة', 'text', ['max_length' => 60],
            'رقم سند الصرف كما صدر من الحسابات. لا يُسجَّل من هذا الطلب قيد مالي في النظام.', 70,
            ['تم صرف الأجرة وفق العقد', 'تأكيد صرف الأجرة وفق العقد (مع رقم السند)']],
    ];

    public function up(): void
    {
        foreach (['MAINTENANCE', 'SERVICE'] as $workflow) {
            $versions = DB::table('workflow_versions as v')
                ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
                ->where('d.code', $workflow)->where('d.subject_type', self::ENTITY)->whereIn('v.status', ['published', 'draft'])
                ->orderByRaw("CASE WHEN v.status = 'published' THEN 0 ELSE 1 END")->orderBy('v.id')
                ->get(['v.id', 'v.version_number', 'v.status']);

            foreach ($versions as $version) {
                $this->patch($workflow, $version);
            }
        }
    }

    /**
     * The new stage, actions and fields carry no request yet, but removing them later would strand the requests
     * that use them and orphan their recorded values, so the rollback keeps the configuration. An administrator
     * can switch any of it off in «مسارات العمل» and «الحقول الديناميكية».
     */
    public function down(): void
    {
        // Nothing to undo.
    }

    private function patch(string $workflow, object $version): void
    {
        foreach (self::STEP_NAMES[$workflow] ?? [] as $code => [$installed, $name]) {
            $step = $this->steps($version)->get($code);
            if ($step && $step->name === $installed) {
                DB::table('workflow_steps')->where('id', $step->id)->update(['name' => $name, 'updated_at' => now()]);
                $this->audit('workflow.step.updated', 'workflow_steps', (int) $step->id, ['name' => $installed], ['name' => $name, 'step' => $code], $workflow, $version);
            }
        }
        foreach (self::TRANSITION_NAMES as [$code, $from, $action, $installed, $name]) {
            if ($code === $workflow) {
                $this->rename($workflow, $version, $from, $action, $installed, $name);
            }
        }

        // The published version must stay as sound as a manual publish would require: what the validator already
        // reported is not this patch's business, anything it reports only afterwards stops the migration.
        $validator = $version->status === 'published' ? app(WorkflowPublishingService::class) : null;
        $before = $validator?->problems((int) $version->id) ?? [];

        $added = $workflow === 'SERVICE' ? $this->fieldWork($workflow, $version) : null;
        if ($workflow === 'MAINTENANCE') {
            foreach (['stores', 'procurement'] as $from) {
                $this->transition($workflow, $version, $from, 'technician', 'return', 'إعادة للاستكمال', true);
            }
        }

        if ($validator) {
            // The new stage has the holder of «internal_execution»; a holder problem it shares is not introduced here.
            $inherited = $added ? app(AssigneeResolver::class)->problems($added) : [];
            $introduced = array_values(array_diff($validator->problems((int) $version->id), $before, $inherited));
            if ($introduced !== []) {
                throw new RuntimeException("تعذر تحديث مسار {$workflow}: ".implode(' | ', $introduced));
            }
        }

        foreach (self::FIELDS as [$code, $step, $action, $field, $label, $type, $validation, $help, $order, $actionName]) {
            if ($code !== $workflow || ! $this->hasAction($version, $step, $action)) {
                continue;
            }
            $inPlace = $this->field($workflow, $step, $action, $field, $label, $type, $validation, $help, $order);
            if ($inPlace && $actionName !== null) {
                $this->rename($workflow, $version, $step, $action, $actionName[0], $actionName[1]);
            }
        }
    }

    /**
     * The stage where a field job waits to be documented, right after «internal_execution», with its three actions.
     * Returns the stage when this run created it. A customised version without the two stages it connects is left
     * as it is.
     */
    private function fieldWork(string $workflow, object $version): ?object
    {
        $steps = $this->steps($version);
        $source = $steps->get('internal_execution');
        if (! $source || ! $steps->has('verification')) {
            return null;
        }

        $added = null;
        if (! $steps->has('field_work')) {
            // Same holder and alternates as the stage it comes from; it waits for field work, so it is not offered
            // as «جاهز للإسناد».
            $config = $this->decode($source->assignment_config);
            unset($config['assignable']);
            $config['field_work'] = true;
            [$order, $moved] = $this->orderAfter((int) $version->id, (int) $source->sort_order);
            $row = [
                'workflow_version_id' => $version->id, 'code' => 'field_work', 'name' => 'عمل ميداني جارٍ: بانتظار توثيق الإنجاز', 'sort_order' => $order,
                'step_type' => 'task', 'assignment_type' => $source->assignment_type, 'assigned_role_code' => $source->assigned_role_code,
                'assignment_config' => json_encode($config, self::JSON), 'sla_hours' => null, 'is_required' => true,
            ];
            $id = (int) DB::table('workflow_steps')->insertGetId([...$row, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('workflow.step.created', 'workflow_steps', $id, null, [...$row, 'assignment_config' => $config, 'moved_later_steps' => $moved], $workflow, $version);
            $added = DB::table('workflow_steps')->where('id', $id)->first();
        }

        $this->transition($workflow, $version, 'internal_execution', 'field_work', 'field_assign', 'تسجيل تكليف ميداني', false);
        // The note of this action is the outcome the requester reads when verifying. It is inserted before the
        // cancel action, so the stepper's expected path goes on to the verification.
        $this->transition($workflow, $version, 'field_work', 'verification', 'document_done', 'توثيق الإنجاز', true);
        $this->transition($workflow, $version, 'field_work', 'internal_execution', 'field_cancel', 'إلغاء التكليف الميداني', true);

        return $added;
    }

    /**
     * A display order right after the given one. The order is unique per version: when the next number is taken,
     * the later stages move up by ten, last first, and keep their order among themselves.
     *
     * @return array{0: int, 1: int} the free order and how many later stages were moved
     */
    private function orderAfter(int $versionId, int $after): array
    {
        $later = DB::table('workflow_steps')->where('workflow_version_id', $versionId)->where('sort_order', '>', $after)
            ->orderByDesc('sort_order')->get(['id', 'sort_order']);
        $gap = $later->isEmpty() ? 20 : (int) $later->last()->sort_order - $after;
        if ($gap >= 2) {
            return [$after + min(intdiv($gap, 2), 5), 0];
        }
        foreach ($later as $step) {
            DB::table('workflow_steps')->where('id', $step->id)->update(['sort_order' => (int) $step->sort_order + 10, 'updated_at' => now()]);
        }

        return [$after + 5, $later->count()];
    }

    /**
     * Adds an action between two stages unless the stage already has one with that code (also a disabled one:
     * an administrator switched it off on purpose).
     */
    private function transition(string $workflow, object $version, string $from, string $to, string $action, string $name, bool $requiresComment): void
    {
        $steps = $this->steps($version);
        $fromStep = $steps->get($from);
        $toStep = $steps->get($to);
        if (! $fromStep || ! $toStep || $this->hasAction($version, $from, $action)) {
            return;
        }
        $row = [
            'workflow_version_id' => $version->id, 'from_step_id' => $fromStep->id, 'to_step_id' => $toStep->id,
            'action_code' => $action, 'name' => $name, 'conditions' => null, 'requires_comment' => $requiresComment, 'is_active' => true,
        ];
        $id = (int) DB::table('workflow_transitions')->insertGetId([...$row, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('workflow.transition.created', 'workflow_transitions', $id, null, [...$row, 'from' => $from, 'to' => $to], $workflow, $version);
    }

    /** Renames an action only while it still carries the installed name. */
    private function rename(string $workflow, object $version, string $from, string $action, string $installed, string $name): void
    {
        $ids = DB::table('workflow_transitions as t')->join('workflow_steps as s', 's.id', '=', 't.from_step_id')
            ->where('t.workflow_version_id', $version->id)->where('s.code', $from)->where('t.action_code', $action)->where('t.name', $installed)
            ->orderBy('t.id')->pluck('t.id');
        foreach ($ids as $id) {
            DB::table('workflow_transitions')->where('id', $id)->update(['name' => $name, 'updated_at' => now()]);
            $this->audit('workflow.transition.updated', 'workflow_transitions', (int) $id, ['name' => $installed], ['name' => $name, 'from' => $from, 'action' => $action], $workflow, $version);
        }
    }

    /**
     * Makes sure the field of one action exists and says whether it is in place for that action. A definition that
     * already uses the code is left exactly as it is; it counts only when it is active on the same stage.
     *
     * @param  array<string, mixed>  $validation
     */
    private function field(string $workflow, string $step, string $action, string $code, string $label, string $type, array $validation, string $help, int $order): bool
    {
        $existing = DB::table('custom_field_definitions')->where('entity_type', self::ENTITY)->where('scope_key', $workflow)->where('code', $code)->first();
        if ($existing) {
            return (bool) $existing->is_active && $existing->step_code === $step;
        }
        $row = [
            'entity_type' => self::ENTITY, 'scope_key' => $workflow, 'step_code' => $step, 'code' => $code, 'label_ar' => $label, 'help_text' => $help,
            'field_type' => $type, 'is_required' => true, 'is_sensitive' => false,
            'validation' => json_encode($validation, self::JSON), 'visible_when' => json_encode(['action_codes' => [$action]], self::JSON),
            'sort_order' => $order, 'is_active' => true,
        ];
        $id = (int) DB::table('custom_field_definitions')->insertGetId([...$row, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('custom_field.created', 'custom_field_definitions', $id, null,
            [...$row, 'validation' => $validation, 'visible_when' => ['action_codes' => [$action]]], $workflow, null);

        return true;
    }

    private function hasAction(object $version, string $step, string $action): bool
    {
        return DB::table('workflow_transitions as t')->join('workflow_steps as s', 's.id', '=', 't.from_step_id')
            ->where('t.workflow_version_id', $version->id)->where('s.code', $step)->where('t.action_code', $action)->exists();
    }

    /** @return Collection<string, object> the version's stages by code, as they are now */
    private function steps(object $version): Collection
    {
        return DB::table('workflow_steps')->where('workflow_version_id', $version->id)->get()->keyBy('code');
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(string $action, string $table, int $id, ?array $before, array $after, string $workflow, ?object $version): void
    {
        $context = ['workflow' => $workflow] + ($version ? ['version' => $version->version_number, 'version_status' => $version->status] : []);
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => $action, 'subject_type' => $table, 'subject_id' => (string) $id,
            'metadata' => json_encode(['before' => $before, 'after' => [...$after, ...$context], 'source' => self::SOURCE], self::JSON),
            'ip_address' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function decode(mixed $json): array
    {
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : $json;

        return is_array($decoded) ? $decoded : [];
    }
};
