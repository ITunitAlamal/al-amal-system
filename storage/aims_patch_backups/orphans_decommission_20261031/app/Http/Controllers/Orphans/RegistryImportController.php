<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Models\OrphanChild;
use App\Services\Configuration\NavigationAccessRules;
use App\Services\Orphans\GuardianLinks;
use App\Services\Orphans\Import\RegistryCorrections;
use App\Services\Orphans\Import\RegistryImports;
use App\Services\Orphans\Import\RegistryIssueTypes;
use App\Services\Orphans\Import\RegistrySheet;
use App\Services\Orphans\Import\RegistryStaging;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\OrphanWorkQueues;
use App\Support\Reports\TabularXlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * «استيراد الكشوف» and «تدقيق البيانات»: private upload, dry-run report, approval gate, staging progress, and the
 * review screens of the staged (provisional) registry. Every action is authorized again in the services.
 */
final class RegistryImportController extends Controller
{
    public function __construct(private readonly OrphanAccess $access, private readonly RegistryImports $imports, private readonly RegistryStaging $staging) {}

    private function ready(): bool
    {
        return Schema::hasTable('orphan_import_batches');
    }

    public function imports(Request $request): InertiaResponse
    {
        $this->access->assert($request->user(), 'imports');

        return Inertia::render('Orphans/Imports', [
            'ready' => $this->ready(),
            'batches' => $this->ready() ? $this->batchList() : [],
            'sheet' => RegistrySheet::SHEET_NAME,
            'columns' => collect(RegistrySheet::COLUMNS)->map(fn (array $c, string $key): array => ['key' => $key, 'letter' => $c[0], 'header' => $c[1], 'type' => $c[2], 'protected' => $c[3], 'note' => $c[5]])->values(),
            'issueTypes' => collect(RegistryIssueTypes::TYPES)->map(fn (array $t, string $code): array => ['code' => $code, 'label' => $t[0], 'severity' => $t[1]])->values(),
            'userId' => $request->user()->id,
            'maxKb' => RegistryImports::MAX_UPLOAD_KB,
        ]);
    }

    public function batches(Request $request): JsonResponse
    {
        $this->access->assert($request->user(), 'imports');
        abort_unless($this->ready(), 503, 'لم تُفعَّل جداول الاستيراد بعد.');

        return response()->json(['batches' => $this->batchList()]);
    }

    public function upload(Request $request): JsonResponse
    {
        abort_unless($this->ready(), 503, 'لم تُفعَّل جداول الاستيراد بعد.');
        $data = $request->validate([
            'file' => 'required|file|max:'.RegistryImports::MAX_UPLOAD_KB.'|mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream',
            'supersedes_id' => 'nullable|integer',
        ], [], ['file' => 'ملف الكشف']);
        abort_unless(strtolower($data['file']->getClientOriginalExtension()) === 'xlsx', 422, 'الملف يجب أن يكون بصيغة XLSX.');
        $batch = $this->imports->upload($request->user(), $data['file'], $data['supersedes_id'] ?? null);

        return response()->json(['public_id' => $batch->public_id], 201);
    }

    public function approve(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['note' => 'required|string|min:10|max:2000', 'confirm' => 'accepted'], [], ['note' => 'ملاحظة الاعتماد', 'confirm' => 'الإقرار']);
        $this->imports->approve($request->user(), $publicId, $data['note']);

        return response()->json(['ok' => true]);
    }

    public function cancel(Request $request, string $publicId): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:10|max:2000'], [], ['reason' => 'سبب الإلغاء']);
        $this->imports->cancel($request->user(), $publicId, $data['reason']);

        return response()->json(['ok' => true]);
    }

    public function retry(Request $request, string $publicId): JsonResponse
    {
        $this->imports->retry($request->user(), $publicId);

        return response()->json(['ok' => true]);
    }

    /**
     * «الأيتام والأوصياء»: the official registry, guardian-centred — views «الأيتام» and «الأوصياء», «إضافة يتيم»
     * (guardian first) and «إضافة وصي». Data comes from GuardianRegistryController; staged import rows are reviewed
     * in «المراجعة والمتابعة» and never listed here as orphans.
     */
    public function records(Request $request): InertiaResponse
    {
        $this->access->assert($request->user(), 'view');
        $user = $request->user();
        $live = fn (string $status) => DB::table('orphan_guardianships')->whereNull('ended_reason')->where('status', $status)->count();

        return Inertia::render('Orphans/Records', [
            'can' => collect(['view', 'sensitive', 'records', 'approve', 'reject', 'imports'])->mapWithKeys(fn (string $c): array => [$c => $this->access->allows($user, $c)]),
            'options' => app(GuardianLinks::class)->options(),
            'statuses' => OrphanChild::STATUS_LABELS,
            'totals' => ['children' => DB::table('orphan_children')->count(), 'guardians' => DB::table('guardians')->count(),
                'pending_links' => $live('pending'), 'approved_links' => $live('approved')],
            'stagedRows' => $this->ready() ? DB::table('orphan_import_rows')->count() : 0,
        ]);
    }

    public function staging(Request $request): InertiaResponse|RedirectResponse
    {
        $this->access->assert($request->user(), 'view');
        // The record lists moved to «السجلات»; old bookmarks of those tabs land there.
        $moved = ['children' => 'children', 'households' => 'households', 'guardians' => 'guardians'];
        if (isset($moved[$request->query('tab')])) {
            return redirect()->route('orphans.records', array_filter(['view' => $moved[$request->query('tab')], 'batch' => $request->query('batch')]));
        }
        if ($request->query('tab') === 'categories') {
            return redirect()->route('orphans.reports', array_filter(['batch' => $request->query('batch')]));
        }
        $user = $request->user();
        $batch = $this->ready() ? $this->staging->batch($request->query('batch')) : null;

        return Inertia::render('Orphans/Staging', [
            'ready' => $this->ready(),
            'batch' => $batch ? ['public_id' => $batch->public_id, 'file_name' => $batch->file_name, 'status' => $batch->status] : null,
            'batches' => $this->ready() ? DB::table('orphan_import_batches')->where('status', 'staged')->orderByDesc('id')->get(['public_id', 'file_name', 'completed_at']) : [],
            'can' => collect(['view', 'sensitive', 'review', 'verify', 'imports'])->mapWithKeys(fn (string $c): array => [$c => $this->access->allows($user, $c)]),
            'issueTypes' => collect(RegistryIssueTypes::TYPES)->map(fn (array $t, string $code): array => ['code' => $code, 'label' => $t[0], 'severity' => $t[1]])->values(),
            'severities' => RegistryIssueTypes::SEVERITIES,
            'reviewStatuses' => RegistryStaging::REVIEW_STATUSES,
            'decisions' => RegistryStaging::DECISIONS,
            'categoryFields' => collect(RegistryStaging::CATEGORY_FIELDS)->map(fn (array $f, string $key): array => ['key' => $key, 'label' => $f[1], 'sensitive' => $f[2]])->values(),
            'reviewers' => $this->ready() ? DB::table('users')->whereIn('id', $this->staging->reviewers())->orderBy('name')->get(['id', 'name']) : [],
            // «يحتاج إلى إجراء»: the import's open issues and the approved registry's work lists, in one queue.
            'staged' => $this->ready() ? $this->staging->workload($user) : null,
            'queues' => app(OrphanWorkQueues::class)->queues($user),
            'plans' => app(OrphanWorkQueues::class)->plansAvailable(),
        ]);
    }

    /**
     * «التقارير والإحصائيات»: the counts of both tiers side by side (never added together) and the distribution of
     * the staged rows by any classification, drilled down to «الأيتام والأوصياء».
     */
    public function reports(Request $request): InertiaResponse
    {
        $user = $request->user();
        $this->access->assert($user, 'view');
        $batch = $this->ready() ? $this->staging->batch($request->query('batch')) : null;

        return Inertia::render('Orphans/Reports', [
            'ready' => $this->ready(),
            'batch' => $batch ? ['public_id' => $batch->public_id, 'file_name' => $batch->file_name, 'completed_at' => $batch->completed_at] : null,
            'staged' => $batch ? $this->staging->dashboard($batch) : null,
            'approved' => [
                'children' => DB::table('orphan_children')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
                'households' => DB::table('orphan_households')->count(),
                'guardianships' => DB::table('orphan_guardianships')->whereNull('ended_reason')->where('status', 'approved')->count(),
                'pending_links' => DB::table('orphan_guardianships')->whereNull('ended_reason')->where('status', 'pending')->count(),
                'sponsorships' => Schema::hasTable('sponsorships') ? DB::table('sponsorships')->where('status', 'active')->count() : null,
            ],
            'can' => collect(['view', 'sensitive', 'review', 'imports'])->mapWithKeys(fn (string $c): array => [$c => $this->access->allows($user, $c)]),
            'categoryFields' => collect(RegistryStaging::CATEGORY_FIELDS)->map(fn (array $f, string $key): array => ['key' => $key, 'label' => $f[1], 'sensitive' => $f[2]])->values(),
            'severities' => RegistryIssueTypes::SEVERITIES,
        ]);
    }

    /** «الكفالات والمساعدات»: one menu entry; opens sponsorships, or relief campaigns for campaign-only staff. */
    public function aid(Request $request): RedirectResponse
    {
        $rules = app(NavigationAccessRules::class);
        if ($rules->allows($request->user(), 'orphans_sponsorships')) {
            return redirect()->route('orphans.sponsorships.index');
        }
        abort_unless($rules->allows($request->user(), 'relief_campaigns'), 403);

        return redirect()->route('relief.campaigns.index');
    }

    public function section(Request $request, string $section): JsonResponse
    {
        abort_unless($this->ready(), 503, 'لم تُفعَّل جداول الاستيراد بعد.');
        $this->access->assert($request->user(), 'view');
        $batch = $this->staging->batch($request->query('batch'));
        if (! $batch) {
            return response()->json(['empty' => true]);
        }
        $filters = $this->filters($request);
        $user = $request->user();

        return response()->json(match ($section) {
            'dashboard' => $this->staging->dashboard($batch),
            'children' => $this->staging->children($user, $batch, $filters),
            'households' => $this->staging->households($user, $batch, $filters),
            'guardians' => $this->staging->guardians($user, $batch, $filters),
            'issues' => $this->staging->issues($user, $batch, $filters),
            'categories' => $this->staging->categories($user, $batch, (string) $request->query('field', 'governorate'), $filters),
            'options' => $this->staging->options($user, $batch),
            default => abort(404),
        });
    }

    public function household(Request $request, string $ref): JsonResponse
    {
        $this->access->assert($request->user(), 'view');
        $batch = $this->staging->batch($request->query('batch'));
        abort_unless($batch, 404);

        return response()->json($this->staging->household($request->user(), $batch, $ref));
    }

    public function guardian(Request $request, string $ref): JsonResponse
    {
        $this->access->assert($request->user(), 'view');
        $batch = $this->staging->batch($request->query('batch'));
        abort_unless($batch, 404);

        return response()->json($this->staging->guardianTree($request->user(), $batch, $ref));
    }

    public function row(Request $request, int $id): JsonResponse
    {
        return response()->json($this->staging->row($request->user(), $id));
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['kind' => 'required|in:identity,household,guardianship', 'decision' => 'required|in:'.implode(',', array_keys(RegistryStaging::DECISIONS)),
            'note' => 'required|string|min:10|max:2000'], [], ['note' => 'الدليل أو مرجع الوثيقة']);
        $this->staging->decide($request->user(), $id, $data['kind'], $data['decision'], $data['note']);

        return response()->json(['ok' => true]);
    }

    public function correct(Request $request, int $id, RegistryCorrections $corrections): JsonResponse
    {
        $corrections->update($request->user(), $id, $request->only(['version', 'changes', 'reason']));

        return response()->json($this->staging->row($request->user(), $id));
    }

    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => 'required|array|min:1|max:500', 'ids.*' => 'integer', 'assignee' => 'nullable|integer']);
        $batch = $this->staging->batch($request->query('batch'));
        abort_unless($batch, 404);

        return response()->json(['assigned' => $this->staging->assign($request->user(), $batch, $data['ids'], $data['assignee'] ?? null)]);
    }

    public function settle(Request $request, int $issue): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:resolved,dismissed,follow_up,open', 'note' => 'required|string|min:10|max:2000'], [], ['note' => 'ملاحظة القرار']);
        $this->staging->settleIssue($request->user(), $issue, $data['status'], $data['note']);

        return response()->json(['ok' => true]);
    }

    public function alias(Request $request): JsonResponse
    {
        $data = $request->validate(['field' => 'required|string|max:40', 'source_key' => 'required|string|max:255', 'value_id' => 'nullable|integer']);
        $this->staging->alias($request->user(), $data['field'], $data['source_key'], $data['value_id'] ?? null);

        return response()->json(['ok' => true]);
    }

    public function export(Request $request, string $format): Response
    {
        $batch = $this->staging->batch($request->query('batch'));
        abort_unless($batch, 404);
        $result = $this->staging->exportRows($request->user(), $batch, $this->filters($request), $request->boolean('unmask'));
        $title = 'سجلات الكشف المؤقتة — بانتظار التدقيق';
        $meta = 'بيانات أولية من المصدر وليست ملفات معتمدة · '.now()->format('Y-m-d H:i').' · '.$request->user()->name.($result['unmasked'] ? ' · الهويات كاملة' : ' · الهويات مخفية جزئيًا');
        $footer = ['عدد الصفوف: '.count($result['rows'])];
        $name = 'AIMS_orphan_staging_'.now()->format('Ymd_His');
        if ($format === 'csv') {
            return response()->streamDownload(function () use ($result): void {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, array_column($result['columns'], 0));
                foreach ($result['rows'] as $row) {
                    fputcsv($out, array_map(fn (string $v): string => preg_match('/^[=+@\-\t\r]/u', $v) && ! is_numeric($v) ? "'".$v : $v, $row));
                }
                fclose($out);
            }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
        }
        if ($format === 'xlsx') {
            $path = TabularXlsx::create($title, $meta, $result['columns'], $result['rows'], $footer, 'تدقيق البيانات');

            return response()->download($path, $name.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store, private'])->deleteFileAfterSend();
        }

        return response()->view('finance.report-print', ['title' => $title, 'area' => 'قسم الكفالات والأيتام', 'generated' => now()->format('Y-m-d H:i'), 'actor' => $request->user()->name,
            'filters' => $meta, 'columns' => $result['columns'], 'rows' => $result['rows'], 'footer' => $footer, 'pdf' => $format === 'pdf'])
            ->withHeaders(['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'q' => 'nullable|string|max:100', 'national_id' => 'nullable|string|max:40', 'review_status' => 'nullable|in:'.implode(',', array_keys(RegistryStaging::REVIEW_STATUSES)),
            'governorate' => 'nullable|string|max:120', 'city' => 'nullable|string|max:160', 'relation' => 'nullable|string|max:120', 'grade' => 'nullable|string|max:120',
            'performance' => 'nullable|string|max:255', 'health' => 'nullable|string|max:255', 'id_status' => 'nullable|string|max:20', 'severity' => 'nullable|in:high,medium,low,info',
            'sex' => 'nullable|in:male,female,missing', 'displaced' => 'nullable|in:yes,no,missing', 'age_min' => 'nullable|integer|between:0,40', 'age_max' => 'nullable|integer|between:0,40',
            'dob_from' => 'nullable|date_format:Y-m-d', 'dob_to' => 'nullable|date_format:Y-m-d', 'issue' => 'nullable|in:'.implode(',', array_keys(RegistryIssueTypes::TYPES)),
            'household' => 'nullable|string|max:200', 'guardian' => 'nullable|string|max:100', 'assigned' => 'nullable|in:me,none', 'status' => 'nullable|in:open,follow_up,resolved,dismissed',
            'type' => 'nullable|in:'.implode(',', array_keys(RegistryIssueTypes::TYPES)), 'include_info' => 'nullable|boolean', 'size_min' => 'nullable|integer|min:0', 'size_max' => 'nullable|integer|min:0',
            'multiple_guardians' => 'nullable|boolean', 'multiple_households' => 'nullable|boolean', 'name_variants' => 'nullable|boolean',
            'q_scope' => 'nullable|in:'.implode(',', array_keys(RegistryStaging::NAME_SCOPES)), 'child_id' => 'nullable|string|max:20', 'guardian_id' => 'nullable|string|max:20',
            'cn1' => 'nullable|string|max:60', 'cn2' => 'nullable|string|max:60', 'cn3' => 'nullable|string|max:60', 'cn4' => 'nullable|string|max:80',
            'gn1' => 'nullable|string|max:60', 'gn2' => 'nullable|string|max:60', 'gn3' => 'nullable|string|max:60', 'gn4' => 'nullable|string|max:80',
            'name_mode' => 'nullable|in:starts,exact', 'mother_status' => 'nullable|string|max:255', 'father_death' => 'nullable|in:yes,missing',
            'age_unknown' => 'nullable|boolean', 'name_incomplete' => 'nullable|boolean', 'claim' => 'nullable|in:pending,'.implode(',', array_keys(RegistryStaging::DECISIONS)),
            'id_placeholder' => 'nullable|boolean', 'row_no' => 'nullable|integer|min:1|max:10000000',
            'sort' => 'nullable|in:'.implode(',', [...array_keys(RegistryStaging::CHILD_SORTS), 'households', 'children']),
        ]);
    }

    private function batchList(): array
    {
        $users = DB::table('users')->pluck('name', 'id');

        return DB::table('orphan_import_batches')->orderByDesc('id')->limit(50)->get()->map(fn (object $b): array => [
            'id' => $b->id, 'public_id' => $b->public_id, 'status' => $b->status, 'file_name' => $b->file_name, 'file_size' => $b->file_size, 'sha' => substr($b->file_sha256, 0, 12),
            'uploaded_by' => $b->uploaded_by, 'uploader' => $users[$b->uploaded_by] ?? null, 'approver' => $users[$b->approved_by] ?? null, 'approval_note' => $b->approval_note,
            'data_rows' => $b->data_rows, 'staged_rows' => $b->staged_rows, 'cursor_row' => $b->cursor_row, 'profile' => json_decode((string) $b->profile, true),
            'header_map' => json_decode((string) $b->header_map, true), 'blockers' => json_decode((string) $b->blockers, true), 'failure' => $b->failure,
            'created_at' => $b->created_at, 'analyzed_at' => $b->analyzed_at, 'approved_at' => $b->approved_at, 'completed_at' => $b->completed_at, 'heartbeat_at' => $b->heartbeat_at,
            'supersedes_id' => $b->supersedes_id,
        ])->all();
    }
}
