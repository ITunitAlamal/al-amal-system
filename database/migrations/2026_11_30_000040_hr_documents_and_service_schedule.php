<?php

use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff document alerts, the appointment of a maintenance or field job, and Accounts told of the works contract.
 *
 * Schema.
 * - hr_employee_file_settings.document_expiry_alert_days: how many days before a document's expiry HR is told
 *   (App\Services\Hr\EmployeeDocumentAlerts, daily command «hr:document-alerts»). Left empty: the number is the
 *   owner's decision, and until it is set only documents already expired are flagged.
 * - notification type «employee_document_alert» (to the HR head).
 *
 * Fields (one optional date-time field "scheduled_at", «الموعد المحدد للتنفيذ», tied to one action each; HR
 * requirements §8.1 step 4 «يكلف الفني ويحدد الموعد», SRS 3.5.4.2 «تحدد المهمة والموعد»):
 * - MAINTENANCE «hr_head» with «assign» (داخلي: تكليف الفني);
 * - SERVICE «internal_execution» with «field_assign» (تسجيل تكليف ميداني).
 * The value is kept with the move; while the request stands at the stage that move opened, «متابعة القسم» counts
 * it as late once the appointment has passed (App\Services\Hr\DepartmentFollowUp). Field definitions are not
 * versioned, so running requests can record it too; it is never required.
 *
 * Workflow (SRS 3.5.4.3 step 4 «الموارد البشرية تعد عقد تنفيذ الأعمال وترسله إلى الحسابات مرتبطًا بالطلب»):
 * SERVICE stage «ext_work» also informs the role finance_accountant when the contract is sent (information only,
 * never an approval; WorkflowEngine "inform_role_codes"). A new published version cloned from the current one and
 * validated like a manual publish, as in 2026_11_30_000038: the previous version is retired for new requests and
 * running requests keep theirs; an open draft gets the same change and moves after the new version.
 *
 * Nothing existing is overwritten; every change writes an audit row. Safe to run twice.
 */
return new class extends Migration
{
    private const SOURCE = 'ترحيل 2026_11_30_000040 (تنبيهات وثائق الموظفين، موعد التنفيذ، وإبلاغ الحسابات بعقد تنفيذ الأعمال)';

    private const ENTITY = 'aims_request';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    private const INFORM_ROLE = 'finance_accountant';

    /** @var list<array{0: string, 1: string, 2: string}> workflow, stage, action */
    private const SCHEDULE_FIELDS = [
        ['MAINTENANCE', 'hr_head', 'assign'],
        ['SERVICE', 'internal_execution', 'field_assign'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('hr_employee_file_settings', 'document_expiry_alert_days')) {
            Schema::table('hr_employee_file_settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('document_expiry_alert_days')->nullable()
                    ->comment('أيام التنبيه قبل انتهاء وثيقة الموظف؛ فارغ = تنبيه بالمنتهية فقط حتى يقرر المالك المدة');
            });
            DB::statement('ALTER TABLE hr_employee_file_settings ADD CONSTRAINT hr_employee_file_settings_alert_days_range
                CHECK (document_expiry_alert_days IS NULL OR document_expiry_alert_days BETWEEN 1 AND 365)');
            $this->audit('schema.column.added', 'hr_employee_file_settings', 1, null, ['column' => 'document_expiry_alert_days', 'value' => null], null, null);
        }

        DB::table('notification_settings')->insertOrIgnore([
            'event_code' => 'employee_document_alert', 'label_ar' => 'تنبيه وثائق الموظفين',
            'description' => 'لرئيس قسم الموارد البشرية: وثائق موظفين منتهية أو قريبة من الانتهاء، وملفات ينقصها وثائق إلزامية.',
            'in_app_enabled' => true, 'sms_enabled' => false, 'sms_template' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (self::SCHEDULE_FIELDS as [$workflow, $step, $action]) {
            $this->scheduleField($workflow, $step, $action);
        }

        $this->publish('SERVICE', fn (object $version): bool => $this->needsAccountsNotice($version), fn (object $version) => $this->accountsNotice($version));
    }

    /**
     * Recorded appointments and the published version stay (an administrator can switch them off); the setting
     * column goes only while nobody has set it.
     */
    public function down(): void
    {
        if (Schema::hasColumn('hr_employee_file_settings', 'document_expiry_alert_days')
            && ! DB::table('hr_employee_file_settings')->whereNotNull('document_expiry_alert_days')->exists()) {
            DB::statement('ALTER TABLE hr_employee_file_settings DROP CONSTRAINT IF EXISTS hr_employee_file_settings_alert_days_range');
            Schema::table('hr_employee_file_settings', fn (Blueprint $table) => $table->dropColumn('document_expiry_alert_days'));
        }
    }

    /** The optional appointment of one action; a definition already using the code is left exactly as it is. */
    private function scheduleField(string $workflow, string $step, string $action): void
    {
        if (DB::table('custom_field_definitions')->where('entity_type', self::ENTITY)->where('scope_key', $workflow)->where('code', 'scheduled_at')->exists()) {
            return;
        }
        $row = [
            'entity_type' => self::ENTITY, 'scope_key' => $workflow, 'step_code' => $step, 'code' => 'scheduled_at',
            'label_ar' => 'الموعد المحدد للتنفيذ',
            'help_text' => 'اختياري: متى يُنفَّذ العمل. يظهر في «متابعة القسم»، ويُعد الطلب متأخرًا إن مضى الموعد وهو في المرحلة نفسها.',
            'field_type' => 'datetime', 'is_required' => false, 'is_sensitive' => false,
            'validation' => json_encode([], self::JSON), 'visible_when' => json_encode(['action_codes' => [$action]], self::JSON),
            'sort_order' => 25, 'is_active' => true,
        ];
        $id = (int) DB::table('custom_field_definitions')->insertGetId([...$row, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('custom_field.created', 'custom_field_definitions', $id, null,
            [...$row, 'validation' => [], 'visible_when' => ['action_codes' => [$action]]], $workflow, null);
    }

    /** «ext_work» exists and does not inform Accounts yet, and the role exists. */
    private function needsAccountsNotice(object $version): bool
    {
        $step = $this->steps($version)->get('ext_work');
        if (! $step || ! DB::table('roles')->where('code', self::INFORM_ROLE)->exists()) {
            return false;
        }
        $roles = $this->decode($step->assignment_config)['inform_role_codes'] ?? [];

        return is_array($roles) && ! in_array(self::INFORM_ROLE, $roles, true);
    }

    private function accountsNotice(object $version): void
    {
        $step = $this->steps($version)->get('ext_work');
        $before = $this->decode($step->assignment_config);
        $roles = is_array($before['inform_role_codes'] ?? null) ? $before['inform_role_codes'] : [];
        $config = [...$before, 'inform_role_codes' => array_values(array_unique([...$roles, self::INFORM_ROLE]))];
        DB::table('workflow_steps')->where('id', $step->id)->update(['assignment_config' => json_encode($config, self::JSON), 'updated_at' => now()]);
        $this->audit('workflow.step.updated', 'workflow_steps', (int) $step->id, ['assignment_config' => $before], ['assignment_config' => $config, 'step' => 'ext_work'], 'SERVICE', $version);
    }

    /**
     * As 2026_11_30_000038: drafts are patched in place; the published version is cloned, patched, validated and
     * published, the old one retired.
     *
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
