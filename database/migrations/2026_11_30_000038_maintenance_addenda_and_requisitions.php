<?php

use App\Services\Maintenance\MaintenanceAddenda;
use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Maintenance addenda and the stock check before purchase.
 *
 * Schema.
 * - aims_requests.parent_request_id / blocks_parent_closure: a «طلب صيانة» opened as an addendum of another
 *   (SRS 3.5.3.3, MT03, T07; HR requirements §8 «معاملة ملحقة»), and whether it keeps the original from closing.
 * - aims_request_separations: the documented separation decision («قرار فصل موثق») that lets the original close while
 *   a required addendum is still open: reason, the person who follows the addendum up, who decided and when.
 * - maintenance_material_addenda and its items: the draft-only tables of the old addendum screen, which never had a
 *   caller, are dropped when both are still empty (App\Services\Maintenance\MaintenanceAddenda replaces them).
 *
 * Workflows (data only; a new published version cloned from the current one and validated like a manual publish: a
 * problem this patch would introduce stops the migration, one the current version already has does not; the previous
 * version is retired for new requests and running requests keep theirs; an open draft gets the same change and moves
 * after the new version, as in 2026_11_30_000030):
 * - MAINTENANCE: its closing end step «completed» runs the effect «maintenance_addenda_closure» (no route changes).
 * - PURCHASE («طلب شراء»): the storekeeper checks stock before procurement (Accounts requirements 9.2 step 3 «يفحص
 *   الرصيد ويصرف عند التوفر أو يثبت عدم التوفر»; §3 «منع شراء مادة متوفرة دون مبرر»). The department head's
 *   recommendation, and the direct send of a requester with no head, now go to the new stage «stores» (role
 *   finance_warehouse, with the holder rules of the maintenance stores stage); from there: «unavailable» → procurement
 *   with the storekeeper's note of what is missing and what was issued (copied into the purchase request), «available»
 *   → the new end step «issued_from_stock» with the stock voucher number, or «return» to the requester. Who approves
 *   a purchase does not change: the Accounts purchase cycle after procurement stays as it is.
 *
 * Nothing here writes stock movements, receipts or accounting entries (owner decision «قواعد المحاسبة لسندات المخزون
 * والدفع» pending). Every change writes an audit row. Safe to run twice: whatever is already there is left alone.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_30_000038 (الطلبات الملحقة بالصيانة وفحص المخزن قبل الشراء)';

    private const ENTITY = 'aims_request';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** @var list<array{0: string, 1: string, 2: string}> code, label, description */
    private const EVENTS = [
        ['maintenance_addendum_opened', 'طلب ملحق بطلب صيانة', 'لمن تقف لديه معاملة الصيانة الأصلية عندما يُفتح طلب ملحق بها.'],
        ['maintenance_addendum_followup', 'تكليف بمتابعة طلب ملحق', 'لمسؤول المتابعة الذي يسميه قرار فصل الملحق عن إغلاق الطلب الأصلي.'],
    ];

    /** @var array<string, array{0: string, 1: string}> PURCHASE action => [installed name, new name] (renamed while still installed) */
    private const PURCHASE_RENAMES = [
        'recommend' => ['الموافقة على الحاجة وإحالتها إلى المشتريات', 'الموافقة على الحاجة وإحالتها للمخزن لفحص التوفر'],
        'submit_procurement' => ['إرسال إلى المشتريات مباشرة (لا يوجد رئيس أعلى)', 'إرسال إلى المخزن مباشرة لفحص التوفر (لا يوجد رئيس أعلى)'],
    ];

    /** @var array<string, array{0: string, 1: string}> MAINTENANCE field => [installed help, new help] */
    private const HELP_TEXTS = [
        'stock_issue_voucher_no' => ['رقم سند صرف المواد من المخزن. لا تُسجَّل من هذا الطلب حركة مخزون في النظام.',
            'رقم سند صرف المواد من المخزن. لا يُنشئ هذا الطلب حركة مخزون؛ إن سجّل المخزن الصرف في النظام على هذا الطلب فاكتب رقم حركة الصرف.'],
        'materials_receipt_no' => ['رقم محضر أو سند استلام المواد المشتراة. لا يُسجَّل من هذا الطلب استلام في المخزون.',
            'رقم محضر أو سند استلام المواد المشتراة. لا يُسجَّل من هذا الطلب استلام في المخزون؛ محضر استلام من النظام (GRN-…) يُتحقق من وجوده وألا يُستخدم لطلبين.'],
    ];

    public function up(): void
    {
        $this->schema();
        $this->dropLegacyAddenda();
        $this->publish('MAINTENANCE', fn (object $version): bool => $this->needsClosureGuard($version), fn (object $version) => $this->closureGuard($version));
        $this->publish('PURCHASE', fn (object $version): bool => $this->needsStores($version), fn (object $version) => $this->stores($version));
        $this->fields();
        $this->events();
    }

    /**
     * The links of recorded addenda are kept: once one exists the schema stays. Workflow versions, fields and
     * notification types stay too (an administrator can switch them off); the old empty addendum tables come back.
     */
    public function down(): void
    {
        if (Schema::hasColumn('aims_requests', 'parent_request_id') && DB::table('aims_requests')->whereNotNull('parent_request_id')->exists()) {
            throw new RuntimeException('توجد طلبات صيانة ملحقة مسجلة؛ لا يُتراجع عن هذا الترحيل حفاظًا على روابطها وقرارات فصلها.');
        }
        Schema::dropIfExists('aims_request_separations');
        DB::statement('ALTER TABLE aims_requests DROP CONSTRAINT IF EXISTS aims_requests_parent_not_self');
        if (Schema::hasColumn('aims_requests', 'parent_request_id')) {
            Schema::table('aims_requests', function (Blueprint $table) {
                $table->dropConstrainedForeignId('parent_request_id');
                $table->dropColumn('blocks_parent_closure');
            });
        }
        if (! Schema::hasTable('maintenance_material_addenda')) {
            (require database_path('migrations/2026_10_18_162621_create_maintenance_material_addenda_tables.php'))->up();
        }
    }

    private function schema(): void
    {
        if (! Schema::hasColumn('aims_requests', 'parent_request_id')) {
            Schema::table('aims_requests', function (Blueprint $table) {
                $table->foreignId('parent_request_id')->nullable()->constrained('aims_requests')->restrictOnDelete();
                $table->boolean('blocks_parent_closure')->default(false);
                $table->index('parent_request_id', 'aims_requests_parent_request_index');
            });
            DB::statement('ALTER TABLE aims_requests ADD CONSTRAINT aims_requests_parent_not_self CHECK (parent_request_id IS NULL OR parent_request_id <> id)');
        }
        if (! Schema::hasTable('aims_request_separations')) {
            Schema::create('aims_request_separations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('parent_request_id')->constrained('aims_requests')->restrictOnDelete();
                $table->foreignId('child_request_id')->unique()->constrained('aims_requests')->restrictOnDelete();
                $table->text('reason');
                $table->foreignId('follow_up_user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('decided_at');
                $table->timestamps();
                $table->index('parent_request_id', 'aims_request_separations_parent_index');
            });
        }
    }

    private function dropLegacyAddenda(): void
    {
        if (! Schema::hasTable('maintenance_material_addenda') || ! Schema::hasTable('maintenance_material_addendum_items')
            || DB::table('maintenance_material_addenda')->exists() || DB::table('maintenance_material_addendum_items')->exists()) {
            return;
        }
        Schema::drop('maintenance_material_addendum_items');
        Schema::drop('maintenance_material_addenda');
        $this->audit('schema.table.dropped', 'maintenance_material_addenda', 0, ['tables' => ['maintenance_material_addenda', 'maintenance_material_addendum_items'], 'rows' => 0],
            ['replaced_by' => 'aims_requests.parent_request_id'], null, null);
    }

    /**
     * @param  callable(object): bool  $needs
     * @param  callable(object): void  $patch
     */
    private function publish(string $code, callable $needs, callable $patch): void
    {
        $definition = DB::table('workflow_definitions')->where('code', $code)->where('subject_type', self::ENTITY)->first();
        if (! $definition) {
            return;
        }
        foreach (DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'draft')->orderBy('id')->get() as $draft) {
            if ($needs($draft)) {
                $patch($draft);
            }
        }

        $published = DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'published')
            ->orderByDesc('version_number')->first();
        if (! $published || ! $needs($published)) {
            return;
        }

        $version = $this->cloneVersion($definition, $published, $code);
        $validator = app(WorkflowPublishingService::class);
        $before = $validator->problems((int) $version->id);
        $patch($version);
        $introduced = array_values(array_diff($validator->problems((int) $version->id), $before));
        if ($introduced !== []) {
            throw new RuntimeException("تعذر نشر الإصدار الجديد من مسار {$code}: ".implode(' | ', $introduced));
        }

        $now = now();
        $retired = DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'published')
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        DB::table('workflow_versions')->whereIn('id', $retired)->update(['status' => 'retired', 'updated_at' => $now]);
        DB::table('workflow_versions')->where('id', $version->id)->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);
        $this->audit('workflow.version.published', 'workflow_versions', (int) $version->id,
            ['status' => 'draft', 'retired_versions' => $retired], ['status' => 'published'], $code, DB::table('workflow_versions')->where('id', $version->id)->first());

        foreach (DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'draft')
            ->where('version_number', '<', (int) $version->version_number)->orderBy('version_number')->get() as $draft) {
            $number = (int) DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->max('version_number') + 1;
            DB::table('workflow_versions')->where('id', $draft->id)->update(['version_number' => $number, 'updated_at' => $now]);
            $this->audit('workflow.version.updated', 'workflow_versions', (int) $draft->id, ['version_number' => (int) $draft->version_number],
                ['version_number' => $number, 'after_published' => (int) $version->id], $code, DB::table('workflow_versions')->where('id', $draft->id)->first());
        }
    }

    /** A new draft holding an exact copy of the version's stages and actions (as WorkflowPublishingService::draftFor). */
    private function cloneVersion(object $definition, object $source, string $code): object
    {
        $now = now();
        $id = (int) DB::table('workflow_versions')->insertGetId([
            'workflow_definition_id' => $definition->id,
            'version_number' => (int) DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->max('version_number') + 1,
            'status' => 'draft', 'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $steps = [];
        foreach (DB::table('workflow_steps')->where('workflow_version_id', $source->id)->orderBy('sort_order')->get() as $step) {
            $row = array_diff_key((array) $step, array_flip(['id', 'workflow_version_id', 'created_at', 'updated_at']));
            $steps[(int) $step->id] = (int) DB::table('workflow_steps')->insertGetId([...$row, 'workflow_version_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('workflow_transitions')->where('workflow_version_id', $source->id)->orderBy('id')->get() as $transition) {
            $row = array_diff_key((array) $transition, array_flip(['id', 'workflow_version_id', 'from_step_id', 'to_step_id', 'created_at', 'updated_at']));
            DB::table('workflow_transitions')->insert([
                ...$row, 'workflow_version_id' => $id,
                'from_step_id' => $steps[(int) $transition->from_step_id], 'to_step_id' => $steps[(int) $transition->to_step_id],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $version = DB::table('workflow_versions')->where('id', $id)->first();
        $this->audit('workflow.version.drafted', 'workflow_versions', $id, null, ['cloned_from' => (int) $source->id, 'cloned_version' => (int) $source->version_number], $code, $version);

        return $version;
    }

    /** The closing end step «completed» has no effect yet (an administrator's own effect is left alone). */
    private function needsClosureGuard(object $version): bool
    {
        $step = $this->steps($version)->get('completed');

        return $step && $step->step_type === 'end' && ! array_key_exists('effect', $this->decode($step->assignment_config))
            && ($this->decode($step->assignment_config)['outcome'] ?? 'completed') === 'completed';
    }

    private function closureGuard(object $version): void
    {
        $step = $this->steps($version)->get('completed');
        $before = $this->decode($step->assignment_config);
        $config = [...$before, 'effect' => MaintenanceAddenda::CLOSURE_EFFECT];
        DB::table('workflow_steps')->where('id', $step->id)->update(['assignment_config' => json_encode($config, self::JSON), 'updated_at' => now()]);
        $this->audit('workflow.step.updated', 'workflow_steps', (int) $step->id, ['assignment_config' => $before], ['assignment_config' => $config, 'step' => 'completed'], 'MAINTENANCE', $version);
    }

    /** The version still sends the request from the department head straight to procurement and has no stores stage. */
    private function needsStores(object $version): bool
    {
        $steps = $this->steps($version);

        return ! $steps->has('stores') && ! $steps->has('issued_from_stock')
            && $steps->has('requester') && $steps->has('department_head') && $steps->has('procurement')
            && $this->transitionTo($version, 'department_head', 'recommend', 'procurement') !== null;
    }

    private function stores(object $version): void
    {
        $steps = $this->steps($version);
        $procurement = $steps['procurement'];
        // The storekeeper's holder rules as on the maintenance stores stage, else the department rule of procurement.
        $model = DB::table('workflow_steps as s')->join('workflow_versions as v', 'v.id', '=', 's.workflow_version_id')
            ->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('d.code', 'MAINTENANCE')->where('v.status', 'published')->where('s.code', 'stores')
            ->where('s.assigned_role_code', 'finance_warehouse')->value('s.assignment_config');
        $base = $this->decode($procurement->assignment_config);
        $config = array_filter([
            'department_scope' => 'any',
            'fallback_role_code' => $this->decode($model)['fallback_role_code'] ?? null,
            'fallback_department_id' => $this->decode($model)['fallback_department_id'] ?? ($base['fallback_department_id'] ?? null),
        ], fn ($value): bool => $value !== null);

        $storesId = $this->step($version, [
            'code' => 'stores', 'name' => 'فحص توفر المواد في المخزن', 'step_type' => 'task', 'assignment_type' => 'role',
            'assigned_role_code' => 'finance_warehouse', 'assignment_config' => $config, 'sla_hours' => null, 'is_required' => true,
        ], (int) $steps['department_head']->sort_order);
        $issuedId = $this->step($version, [
            'code' => 'issued_from_stock', 'name' => 'صُرفت المواد من المخزن', 'step_type' => 'end', 'assignment_type' => null,
            'assigned_role_code' => null, 'assignment_config' => ['outcome' => 'completed'], 'sla_hours' => null, 'is_required' => true,
        ], (int) ($steps->get('handed')?->sort_order ?? 900));

        foreach (['department_head' => 'recommend', 'requester' => 'submit_procurement'] as $from => $action) {
            $transition = $this->transitionTo($version, $from, $action, 'procurement');
            if ($transition === null) {
                continue;
            }
            [$installed, $name] = self::PURCHASE_RENAMES[$action];
            $update = ['to_step_id' => $storesId] + ($transition->name === $installed ? ['name' => $name] : []);
            DB::table('workflow_transitions')->where('id', $transition->id)->update([...$update, 'updated_at' => now()]);
            $this->audit('workflow.transition.updated', 'workflow_transitions', (int) $transition->id,
                ['to' => 'procurement', 'name' => $transition->name], ['to' => 'stores', 'name' => $update['name'] ?? $transition->name, 'from' => $from, 'action' => $action], 'PURCHASE', $version);
        }

        // «unavailable» first: the stepper's expected path goes on to procurement.
        $this->transition($version, $storesId, (int) $procurement->id, 'unavailable', 'غير متوفرة أو ناقصة: إحالة للمشتريات (اذكر الناقص وما صُرف)', true);
        $this->transition($version, $storesId, $issuedId, 'available', 'المواد متوفرة: صُرفت من المخزن (مع رقم السند)', false);
        $this->transition($version, $storesId, (int) $steps['requester']->id, 'return', 'إعادة للموظف للاستيضاح', true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function step(object $version, array $row, int $after): int
    {
        [$order, $moved] = $this->orderAfter((int) $version->id, $after);
        $insert = [...$row, 'workflow_version_id' => $version->id, 'sort_order' => $order,
            'assignment_config' => json_encode($row['assignment_config'], self::JSON)];
        $id = (int) DB::table('workflow_steps')->insertGetId([...$insert, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('workflow.step.created', 'workflow_steps', $id, null, [...$row, 'sort_order' => $order, 'moved_later_steps' => $moved], 'PURCHASE', $version);

        return $id;
    }

    /**
     * A display order right after the given one; the order is unique per version (as 2026_11_30_000023).
     *
     * @return array{0: int, 1: int}
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

    private function transition(object $version, int $fromId, int $toId, string $action, string $name, bool $requiresComment): void
    {
        if (DB::table('workflow_transitions')->where('workflow_version_id', $version->id)->where('from_step_id', $fromId)->where('action_code', $action)->exists()) {
            return;
        }
        $row = [
            'workflow_version_id' => $version->id, 'from_step_id' => $fromId, 'to_step_id' => $toId,
            'action_code' => $action, 'name' => $name, 'conditions' => null, 'requires_comment' => $requiresComment, 'is_active' => true,
        ];
        $id = (int) DB::table('workflow_transitions')->insertGetId([...$row, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('workflow.transition.created', 'workflow_transitions', $id, null, $row, 'PURCHASE', $version);
    }

    private function transitionTo(object $version, string $from, string $action, string $to): ?object
    {
        return DB::table('workflow_transitions as t')->join('workflow_steps as f', 'f.id', '=', 't.from_step_id')
            ->join('workflow_steps as s', 's.id', '=', 't.to_step_id')
            ->where('t.workflow_version_id', $version->id)->where('f.code', $from)->where('t.action_code', $action)->where('s.code', $to)
            ->first(['t.id', 't.name']);
    }

    /**
     * PURCHASE «available» records the stock voucher number; the maintenance voucher fields say what AIMS checks.
     * An existing definition is never overwritten; a help text changes only while it is still the installed one.
     */
    private function fields(): void
    {
        $existing = DB::table('custom_field_definitions')->where('entity_type', self::ENTITY)->where('scope_key', 'PURCHASE')->where('code', 'stock_issue_voucher_no')->exists();
        if (! $existing) {
            $row = [
                'entity_type' => self::ENTITY, 'scope_key' => 'PURCHASE', 'step_code' => 'stores', 'code' => 'stock_issue_voucher_no',
                'label_ar' => 'رقم سند الصرف المخزني',
                'help_text' => 'رقم سند صرف المواد من المخزن. إن سجّل المخزن الصرف في النظام على هذا الطلب فاكتب رقم حركة الصرف.',
                'field_type' => 'text', 'is_required' => true, 'is_sensitive' => false,
                'validation' => json_encode(['max_length' => 60], self::JSON), 'visible_when' => json_encode(['action_codes' => ['available']], self::JSON),
                'sort_order' => 60, 'is_active' => true,
            ];
            $id = (int) DB::table('custom_field_definitions')->insertGetId([...$row, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit('custom_field.created', 'custom_field_definitions', $id, null,
                [...$row, 'validation' => ['max_length' => 60], 'visible_when' => ['action_codes' => ['available']]], 'PURCHASE', null);
        }

        foreach (self::HELP_TEXTS as $code => [$installed, $help]) {
            $field = DB::table('custom_field_definitions')->where('entity_type', self::ENTITY)->where('scope_key', 'MAINTENANCE')->where('code', $code)->first(['id', 'help_text']);
            if ($field && $field->help_text === $installed) {
                DB::table('custom_field_definitions')->where('id', $field->id)->update(['help_text' => $help, 'updated_at' => now()]);
                $this->audit('custom_field.updated', 'custom_field_definitions', (int) $field->id, ['help_text' => $installed], ['help_text' => $help, 'code' => $code], 'MAINTENANCE', null);
            }
        }
    }

    private function events(): void
    {
        $now = now();
        foreach (self::EVENTS as [$code, $label, $description]) {
            DB::table('notification_settings')->insertOrIgnore([
                'event_code' => $code, 'label_ar' => $label, 'description' => $description,
                'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
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
    private function audit(string $action, string $table, int $id, ?array $before, array $after, ?string $workflow, ?object $version): void
    {
        $context = ($workflow ? ['workflow' => $workflow] : []) + ($version ? ['version' => (int) $version->version_number, 'version_status' => $version->status] : []);
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
