<?php

use App\Services\Workflow\AimsRequestEffects;
use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PURCHASE («طلب شراء»): a request the executive director reopened after procurement accepted it may no longer close
 * «صُرف من المخزن» (issued_from_stock) or «مرفوض» (rejected) while the purchase request it opened in Accounts is
 * still live, so the goods are never both issued from stock and bought (Engineering 01, CR-01). The two end steps
 * get the check-only effect AimsRequestEffects::PURCHASE_CLOSED_GUARD.
 *
 * Data only. The change is a new published version cloned from the current one and validated like a manual publish
 * (as 2026_11_30_000030 and 000038): a problem this patch would introduce stops the migration, one the current version
 * already has does not. The previous version is retired for new requests; running requests stay on the version they
 * started with. An open draft gets the same effect and is renumbered after the new version. An end step that already
 * names another effect (an administrator's or a migration's) is left alone. Every change writes an audit row. Safe to
 * run twice: a version that already carries the guard is not cloned again.
 */
return new class extends Migration
{
    private const CODE = 'PURCHASE';

    private const ENTITY = 'aims_request';

    private const STEPS = ['issued_from_stock', 'rejected'];

    private const SOURCE = 'ترحيل 2026_11_30_000045 (منع إغلاق طلب الشراء بالصرف أو الرفض وطلب الشراء في الحسابات قائم)';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public function up(): void
    {
        $definition = DB::table('workflow_definitions')->where('code', self::CODE)->where('subject_type', self::ENTITY)->first();
        if (! $definition) {
            return;
        }

        foreach (DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'draft')->orderBy('id')->get() as $draft) {
            $this->guard($draft);
        }

        $published = DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'published')
            ->orderByDesc('version_number')->first();
        if (! $published || $this->missing($published)->isEmpty()) {
            return;
        }

        $version = $this->cloneVersion($definition, $published);
        $validator = app(WorkflowPublishingService::class);
        $before = $validator->problems((int) $version->id);
        $this->guard($version);
        $introduced = array_values(array_diff($validator->problems((int) $version->id), $before));
        if ($introduced !== []) {
            throw new RuntimeException('تعذر نشر الإصدار الجديد من مسار طلب الشراء: '.implode(' | ', $introduced));
        }

        $now = now();
        $retired = DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'published')
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        DB::table('workflow_versions')->whereIn('id', $retired)->update(['status' => 'retired', 'updated_at' => $now]);
        DB::table('workflow_versions')->where('id', $version->id)->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);
        $this->audit('workflow.version.published', 'workflow_versions', (int) $version->id,
            ['status' => 'draft', 'retired_versions' => $retired], ['status' => 'published'], DB::table('workflow_versions')->where('id', $version->id)->first());

        // An open draft now sorts below the version just published; it moves after it so publishing it later keeps
        // the administrator's edits and the next draft is cloned from the newest version.
        foreach (DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'draft')
            ->where('version_number', '<', (int) $version->version_number)->orderBy('version_number')->get() as $draft) {
            $number = (int) DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->max('version_number') + 1;
            DB::table('workflow_versions')->where('id', $draft->id)->update(['version_number' => $number, 'updated_at' => $now]);
            $this->audit('workflow.version.updated', 'workflow_versions', (int) $draft->id, ['version_number' => (int) $draft->version_number],
                ['version_number' => $number, 'after_published' => (int) $version->id], DB::table('workflow_versions')->where('id', $draft->id)->first());
        }
    }

    /**
     * The published version and its history stay; requests already on it keep it. Nothing to undo.
     */
    public function down(): void
    {
        // Nothing to undo.
    }

    /**
     * The version's end steps of this patch that have no effect yet.
     *
     * @return Collection<int, object>
     */
    private function missing(object $version): Collection
    {
        return DB::table('workflow_steps')->where('workflow_version_id', $version->id)->whereIn('code', self::STEPS)
            ->where('step_type', 'end')->orderBy('sort_order')->get()
            ->filter(fn (object $step): bool => ! array_key_exists('effect', $this->decode($step->assignment_config)))->values();
    }

    private function guard(object $version): void
    {
        foreach ($this->missing($version) as $step) {
            $before = $this->decode($step->assignment_config);
            $config = [...$before, 'effect' => AimsRequestEffects::PURCHASE_CLOSED_GUARD];
            DB::table('workflow_steps')->where('id', $step->id)->update(['assignment_config' => json_encode($config, self::JSON), 'updated_at' => now()]);
            $this->audit('workflow.step.updated', 'workflow_steps', (int) $step->id, ['assignment_config' => $before],
                ['assignment_config' => $config, 'step' => $step->code], $version);
        }
    }

    /** A new draft holding an exact copy of the version's stages and actions (as WorkflowPublishingService::draftFor). */
    private function cloneVersion(object $definition, object $source): object
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
        $this->audit('workflow.version.drafted', 'workflow_versions', $id, null, ['cloned_from' => (int) $source->id, 'cloned_version' => (int) $source->version_number], $version);

        return $version;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(string $action, string $table, int $id, ?array $before, array $after, object $version): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => null, 'action' => $action, 'subject_type' => $table, 'subject_id' => (string) $id,
            'metadata' => json_encode(['before' => $before, 'after' => [...$after, 'workflow' => self::CODE, 'version' => (int) $version->version_number,
                'version_status' => $version->status], 'source' => self::SOURCE], self::JSON),
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
