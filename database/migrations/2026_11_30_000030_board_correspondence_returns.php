<?php

use App\Services\Workflow\WorkflowPublishingService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Board decisions («تبليغ ومتابعة قرار مجلس الإدارة», BOARD_CORRESPONDENCE): the send-backs of the approved cycle
 * (SRS 3.6.2 «تتحقق من اكتمال البيانات» and «يعاد فتحه بقرار موثق»; executive director requirements §5–§6
 * «يعتمد الإغلاق أو يعاد القرار للمتابعة»; TRANSFORM §3.3), each with a written reason:
 * - «register» → «record» (return): the Secretariat sends an incomplete decision back to the board secretary, who
 *   sends it again with the missing data as the note and documents of that action; the issued text is kept;
 * - «allocate» → «register» (return): the executive director sends the registration back to the Secretariat;
 * - «authorized_close» → «review» (return_followup): the closure authority returns the decision to the executive
 *   director when execution is not complete, instead of closing it; the director can return it to the department.
 * App\Services\Correspondence\CorrespondenceWorkflow checks who may receive each of these stages.
 *
 * Data only. The change is a new published version cloned from the current one and validated like a manual
 * publish: a problem this patch would introduce stops the migration, one the current version already has does not.
 * The previous version is retired for new decisions; running decisions stay on the version they started with
 * (none runs today). An open draft gets the same actions and is renumbered after the new version, so publishing it
 * later drops nothing and the next draft starts from the newest version. An action an
 * administrator already put on the same stage with the same code (also a disabled one) is left alone, and a
 * customised version without the two stages is skipped. Every change writes an audit row. Safe to run twice.
 */
return new class extends Migration
{
    private const CODE = 'BOARD_CORRESPONDENCE';

    private const SUBJECT = 'official_correspondence';

    private const SOURCE = 'ترحيل 2026_11_30_000030 (إعادة قرار مجلس الإدارة للاستكمال والمتابعة)';

    private const JSON = JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> from stage, to stage, action, name */
    private const TRANSITIONS = [
        ['register', 'record', 'return', 'إعادة لأمين السر لاستكمال بيانات القرار'],
        ['allocate', 'register', 'return', 'إعادة للسكرتاريا لاستكمال التسجيل'],
        ['authorized_close', 'review', 'return_followup', 'إعادة القرار للمتابعة لعدم اكتمال التنفيذ'],
    ];

    public function up(): void
    {
        $definition = DB::table('workflow_definitions')->where('code', self::CODE)->where('subject_type', self::SUBJECT)->first();
        if (! $definition) {
            return;
        }

        foreach (DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'draft')->orderBy('id')->get() as $draft) {
            foreach ($this->missing($draft) as [$from, $to, $action, $name]) {
                $this->transition($draft, $from, $to, $action, $name);
            }
        }

        $published = DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'published')
            ->orderByDesc('version_number')->first();
        if (! $published || $this->missing($published) === []) {
            return;
        }

        $version = $this->cloneVersion($definition, $published);
        $validator = app(WorkflowPublishingService::class);
        $before = $validator->problems((int) $version->id);
        foreach ($this->missing($version) as [$from, $to, $action, $name]) {
            $this->transition($version, $from, $to, $action, $name);
        }
        $introduced = array_values(array_diff($validator->problems((int) $version->id), $before));
        if ($introduced !== []) {
            throw new RuntimeException('تعذر نشر الإصدار الجديد من مسار قرارات مجلس الإدارة: '.implode(' | ', $introduced));
        }

        $now = now();
        $retired = DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'published')
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();
        DB::table('workflow_versions')->whereIn('id', $retired)->update(['status' => 'retired', 'updated_at' => $now]);
        DB::table('workflow_versions')->where('id', $version->id)->update(['status' => 'published', 'published_at' => $now, 'updated_at' => $now]);
        $this->audit('workflow.version.published', 'workflow_versions', (int) $version->id,
            ['status' => 'draft', 'retired_versions' => $retired], ['status' => 'published'], DB::table('workflow_versions')->where('id', $version->id)->first());

        // An open draft now sorts below the version just published. It moves after it, so publishing it later keeps
        // the administrator's edits and the next draft is cloned from the newest version (draftFor takes the highest).
        foreach (DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->where('status', 'draft')
            ->where('version_number', '<', (int) $version->version_number)->orderBy('version_number')->get() as $draft) {
            $number = (int) DB::table('workflow_versions')->where('workflow_definition_id', $definition->id)->max('version_number') + 1;
            DB::table('workflow_versions')->where('id', $draft->id)->update(['version_number' => $number, 'updated_at' => $now]);
            $this->audit('workflow.version.updated', 'workflow_versions', (int) $draft->id, ['version_number' => (int) $draft->version_number],
                ['version_number' => $number, 'after_published' => (int) $version->id], DB::table('workflow_versions')->where('id', $draft->id)->first());
        }
    }

    /**
     * The published version and its history stay; an administrator can switch the new actions off in «مسارات العمل».
     */
    public function down(): void
    {
        // Nothing to undo.
    }

    /**
     * The actions of this patch the version still lacks: both stages exist and the first has no action with that code.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function missing(object $version): array
    {
        $steps = DB::table('workflow_steps')->where('workflow_version_id', $version->id)->pluck('id', 'code');

        return array_values(array_filter(self::TRANSITIONS, fn (array $row): bool => $steps->has($row[0]) && $steps->has($row[1])
            && ! DB::table('workflow_transitions')->where('workflow_version_id', $version->id)
                ->where('from_step_id', $steps[$row[0]])->where('action_code', $row[2])->exists()));
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

    private function transition(object $version, string $from, string $to, string $action, string $name): void
    {
        $steps = DB::table('workflow_steps')->where('workflow_version_id', $version->id)->pluck('id', 'code');
        $row = [
            'workflow_version_id' => $version->id, 'from_step_id' => $steps[$from], 'to_step_id' => $steps[$to],
            'action_code' => $action, 'name' => $name, 'conditions' => null, 'requires_comment' => true, 'is_active' => true,
        ];
        $id = (int) DB::table('workflow_transitions')->insertGetId([...$row, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('workflow.transition.created', 'workflow_transitions', $id, null, [...$row, 'from' => $from, 'to' => $to], $version);
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
};
