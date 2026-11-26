<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Orphans\Identity;
use App\Services\Orphans\Import\ImportFields;
use App\Services\Orphans\Import\ImportPipeline;
use App\Services\Orphans\Import\ImportPolicy;
use App\Services\Orphans\OrphanAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** «الاستيراد الذكي» — استيراد البيانات والكشوفات مع التحقق المسبق واكتشاف الأخطاء والتكرار. */
final class ImportController extends Controller
{
    /** Row filters on the batch page; «ناقص بيانات» and «مستورد» cut across categories. */
    public const FILTERS = ['missing' => 'ناقص بيانات', 'imported' => 'مستورد (معتمد مبدئيًا)', 'held' => 'لم يُدرج (معلّق أو فشل)'];

    /** Largest import file (KB). php.ini upload_max_filesize must be at least this (64M, post_max_size 80M). */
    public const MAX_UPLOAD_KB = ImportPipeline::MAX_BYTES / 1024;

    private const MISSING_CODES = ['missing_guardian', 'sex_unknown', 'invalid_date', 'guardian_unidentified', 'invalid_id', 'placeholder_id'];

    public function __construct(private readonly OrphanAccess $access, private readonly ImportPipeline $pipeline, private readonly AuditService $audit) {}

    public function index(Request $request): Response
    {
        $this->access->assert($request->user(), 'import');

        return Inertia::render('Orphans/Import/Index', [
            'batches' => DB::table('import_batches as b')->leftJoin('users as u', 'u.id', '=', 'b.uploaded_by')->leftJoin('campaigns as c', 'c.id', '=', 'b.campaign_id')->orderByDesc('b.id')
                ->select(['b.*', 'u.name as uploader', 'c.reference_no as campaign_ref'])->paginate(20)->through(fn ($b) => ['public_id' => $b->public_id, 'reference_no' => $b->reference_no, 'purpose' => ImportFields::PURPOSES[$b->purpose], 'status' => $b->status,
                    'status_label' => ImportPipeline::STATUS[$b->status], 'original_name' => $b->original_name, 'rows_total' => $b->rows_total, 'created_at' => $b->created_at, 'uploaded_by' => $b->uploader, 'campaign' => $b->campaign_ref]),
            'purposes' => ImportFields::PURPOSES, 'purpose' => $request->query('purpose'),
            'campaigns' => DB::table('campaigns')->whereNotIn('status', ['closed', 'cancelled'])->orderByDesc('id')->get(['id', 'reference_no', 'title', 'status']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // A file the server itself refused (size above php.ini, missing temp folder…) gets its real reason, not «تعذر رفع الملف».
        $upload = $request->file('file');
        if ($upload && ! $upload->isValid()) {
            throw ValidationException::withMessages(['file' => in_array($upload->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'حجم الملف أكبر من الحد الذي يقبله الخادم حاليًا ('.ini_get('upload_max_filesize').'). قسّم الملف أو اطلب من مدير النظام رفع الحد.'
                : 'تعذر رفع الملف بسبب الخادم (رمز '.$upload->getError().'). أعد المحاولة، وإن تكرر فأبلغ مدير النظام.']);
        }
        $data = $request->validate(['file' => 'required|file|max:'.self::MAX_UPLOAD_KB, 'purpose' => ['required', Rule::in(array_keys(ImportFields::PURPOSES))],
            'campaign_id' => 'nullable|integer|exists:campaigns,id', 'encoding' => ['nullable', Rule::in(['UTF-8', 'Windows-1256', 'ISO-8859-6'])]], [], ['file' => 'الملف']);
        $batch = $this->pipeline->upload($data['file'], $data['purpose'], $data['campaign_id'] ?? null, $data['encoding'] ?? null, $request->user());
        $same = DB::table('import_batches')->where('sha256', $batch->sha256)->where('id', '<>', $batch->id)->whereNotIn('status', ['cancelled'])->value('reference_no');

        return redirect('/orphans/import/'.$batch->public_id)->with('success', $same ? 'تنبيه: الملف نفسه رُفع سابقًا في الدفعة '.$same.'. التنفيذ لا يكرر السجلات القائمة.' : 'تم رفع الملف؛ اختر ورقة العمل وطابق الأعمدة.');
    }

    public function show(Request $request, string $publicId): Response
    {
        $this->access->assert($request->user(), 'import');
        $batch = $this->find($publicId);
        $sheet = $request->query('sheet', $batch->sheet);
        $sheets = $batch->status === 'cancelled' ? [] : $this->pipeline->sheets($batch);
        $sheet ??= $sheets[0] ?? null;
        $headerRow = (int) $request->query('header_row', $batch->header_row);
        $preview = in_array($batch->status, ['uploaded', 'mapped'], true) ? $this->pipeline->preview($batch, $sheet, max(1, $headerRow)) : null;
        $identity = $this->access->allows($request->user(), 'identity');
        $category = $request->query('category');
        $filter = $request->query('filter');
        $rows = in_array($batch->status, ['reviewing', 'approved', 'executing', 'partially_imported', 'imported', 'completed'], true)
            ? DB::table('import_staged_rows as r')->leftJoin('import_execution_events as x', 'x.row_id', '=', 'r.id')->leftJoin('orphans as o', 'o.id', '=', 'x.orphan_id')->where('r.batch_id', $batch->id)
                ->when($category, fn ($q, $c) => $q->where('r.category', $c))
                ->when($request->query('issue'), fn ($q, $code) => $q->whereExists(fn ($w) => $w->from('import_row_issues as i')->whereColumn('i.row_id', 'r.id')->where('i.code', $code)->where('i.resolution', ImportPipeline::OPEN)))
                ->when($filter === 'missing', fn ($q) => $q->whereExists(fn ($w) => $w->from('import_row_issues as i')->whereColumn('i.row_id', 'r.id')->whereIn('i.code', self::MISSING_CODES)->where('i.resolution', ImportPipeline::OPEN)))
                ->when($filter === 'imported', fn ($q) => $q->whereIn('x.outcome', ['created', 'linked', 'updated']))
                ->when($filter === 'held', fn ($q) => $q->whereIn('x.outcome', ['held_for_review', 'failed']))
                ->orderBy('r.row_no')->select(['r.id', 'r.row_no', 'r.category', 'r.proposed_action', 'r.normalized', 'r.matches', 'r.diff', 'r.decision', 'r.corrected', 'r.inference', 'r.family_key',
                    'x.outcome', 'x.message', 'o.public_id as orphan_public_id', 'o.reference_no as orphan_reference', 'o.status as orphan_status'])
                ->paginate(40)->withQueryString()->through(function ($r) use ($identity, $batch): array {
                    $inference = json_decode((string) $r->inference, true);
                    if ($inference) {
                        $inference['guardian_id'] = $this->identity($inference['guardian_id'] ?? '', $identity);
                        unset($inference['group_key'], $inference['contact_phone'], $inference['contact_phone_2']);
                    }

                    return [...collect((array) $r)->except(['family_key', 'inference'])->all(), 'normalized' => $this->safeRow(json_decode($r->normalized, true), $identity),
                        'matches' => json_decode((string) $r->matches, true), 'diff' => json_decode((string) $r->diff, true), 'inference' => $inference,
                        'family_size' => $r->family_key ? DB::table('import_staged_rows')->where('batch_id', $batch->id)->where('family_key', $r->family_key)->count() : 1,
                        'issues' => DB::table('import_row_issues')->where('row_id', $r->id)->get(['code', 'severity', 'field', 'message', 'resolution'])
                            ->map(fn ($i) => [...(array) $i, 'label' => ImportPolicy::label($i->code)])];
                })
            : null;

        return Inertia::render('Orphans/Import/Batch', [
            'batch' => [...collect((array) $batch)->except(['path', 'sha256', 'id'])->all(), 'purpose_label' => ImportFields::PURPOSES[$batch->purpose], 'status_label' => ImportPipeline::STATUS[$batch->status],
                'mapping' => json_decode((string) $batch->mapping, true) ?: [], 'summary' => json_decode((string) $batch->summary, true), 'sha_short' => substr($batch->sha256, 0, 12),
                'campaign' => $batch->campaign_id ? DB::table('campaigns')->where('id', $batch->campaign_id)->first(['public_id', 'reference_no', 'title']) : null],
            'sheets' => $sheets, 'sheet' => $sheet, 'headerRow' => $headerRow, 'preview' => $preview,
            'suggested' => $preview ? ImportFields::suggest($batch->purpose, $preview['headers']) : [],
            'fields' => collect(ImportFields::for($batch->purpose))->map(fn ($f, $k) => ['key' => $k, 'label' => $f[0], 'sensitivity' => $f[2]])->values(),
            'profiles' => DB::table('import_mapping_profiles')->where('purpose', $batch->purpose)->orderByDesc('id')->get(['id', 'name', 'mapping']),
            'rows' => $rows, 'category' => $category, 'categories' => ImportPipeline::CATEGORIES, 'abilities' => $this->access->abilities($request->user()),
            'filter' => $filter, 'filters' => self::FILTERS, 'outcomes' => ImportPipeline::OUTCOMES, 'policy' => ImportPolicy::matrix(),
            'issueLabels' => collect(ImportPolicy::CONFIGURABLE)->map(fn ($d) => $d[0])->merge(ImportPolicy::FIXED_LABELS)->all(),
            'canReset' => $this->access->canAssign($request->user()),
            'backlog' => fn () => $this->backlog((int) $batch->id),
            // List wordings with no matching value or alias: a read-only review queue, loaded only when asked (several
            // seconds on a large batch), cached per batch state.
            'canCheckOptions' => $batch->purpose === 'registry' && $rows !== null,
            'unmappedOptions' => Inertia::optional(fn () => $batch->purpose === 'registry' && $rows !== null
                ? cache()->remember('orphans.import.unmapped.'.$batch->id.'.'.$batch->updated_at, 600, fn () => $this->pipeline->unmappedOptions((int) $batch->id)) : []),
        ]);
    }

    /**
     * Review progress straight from the staged rows: each category by the reviewer's decision and by the result in
     * the registry, and the failures by cause (names and IDs removed). Counts only.
     *
     * @return array{rows: list<array<string, mixed>>, failures: list<array{cause: string, n: int}>}
     */
    private function backlog(int $batchId): array
    {
        $cells = DB::select("SELECT r.category, COALESCE(r.decision, 'none') AS decision, COALESCE(x.outcome, 'none') AS outcome, count(*) AS n
            FROM import_staged_rows r LEFT JOIN import_execution_events x ON x.row_id = r.id WHERE r.batch_id = ? GROUP BY 1, 2, 3", [$batchId]);
        $rows = collect($cells)->groupBy('category')->map(function ($group, string $category): array {
            $sum = fn (callable $test): int => (int) $group->filter($test)->sum('n');
            $done = ['created', 'linked', 'updated'];

            return ['category' => $category, 'label' => ImportPipeline::CATEGORIES[$category] ?? $category, 'total' => $sum(fn () => true),
                'undecided' => $sum(fn ($c) => $c->decision === 'none' && ! in_array($c->outcome, $done, true) && $category !== 'ready'),
                'accepted' => $sum(fn ($c) => $c->decision === 'accept'), 'skipped' => $sum(fn ($c) => $c->decision === 'skip'),
                'imported' => $sum(fn ($c) => in_array($c->outcome, $done, true)),
                'held' => $sum(fn ($c) => $c->outcome === 'held_for_review'), 'failed' => $sum(fn ($c) => $c->outcome === 'failed')];
        })->values()->all();
        $failures = collect(DB::select("SELECT regexp_replace(regexp_replace(message, '«[^»]*»', '«…»', 'g'), '[0-9]{5,}', '…', 'g') AS cause, count(*) AS n
            FROM import_execution_events WHERE batch_id = ? AND outcome = 'failed' GROUP BY 1 ORDER BY 2 DESC LIMIT 10", [$batchId]))
            ->map(fn (object $f): array => ['cause' => (string) $f->cause, 'n' => (int) $f->n])->all();

        return ['rows' => $rows, 'failures' => $failures];
    }

    public function mapping(Request $request, string $publicId): RedirectResponse
    {
        $batch = $this->find($publicId);
        $data = $request->validate(['sheet' => 'nullable|string|max:150', 'header_row' => 'required|integer|min:1|max:50', 'mapping' => 'array', 'mapping.*' => 'nullable|string|max:60',
            'profile_name' => 'nullable|string|max:150']);
        $this->pipeline->saveMapping($batch, $data['sheet'] ?? null, (int) $data['header_row'], array_filter($data['mapping'] ?? []), $data['profile_name'] ?? null, $request->user());
        $this->pipeline->startAnalysis($this->find($publicId), $request->user());
        $batch = $this->find($publicId);

        return redirect('/orphans/import/'.$publicId)->with('success', $batch->status === 'reviewing' ? 'اكتمل الفحص الذكي؛ راجع الصفوف قبل الاعتماد.' : 'بدأ الفحص الذكي؛ يستمر في الخلفية للملفات الكبيرة.');
    }

    public function step(Request $request, string $publicId): RedirectResponse
    {
        $batch = $this->find($publicId);
        $this->access->assert($request->user(), 'import');
        $this->pipeline->inline((int) $batch->id);

        return back()->with('success', 'يستمر العمل في الخلفية كل دقيقة؛ حدّث الصفحة لمتابعة التقدم.');
    }

    public function correct(Request $request, string $publicId, int $row): RedirectResponse
    {
        $data = $request->validate(['fields' => 'required|array', 'fields.*' => 'nullable|string|max:500']);
        $this->pipeline->correct($this->find($publicId), $row, $data['fields'], $request->user());

        return back()->with('success', 'صُحّح الصف المرحلي وأعيد فحصه؛ السجل الأصلي لم يتغير.');
    }

    public function decide(Request $request, string $publicId): RedirectResponse
    {
        $data = $request->validate(['rows' => 'required|array|min:1|max:500', 'rows.*' => 'integer', 'decision' => ['required', Rule::in(['accept', 'skip'])],
            'reason' => 'required_if:decision,accept|nullable|string|min:5|max:500'], ['reason.required_if' => 'اكتب سبب القبول (يُحفظ في سجل النظام).']);
        $result = $this->pipeline->decide($this->find($publicId), $data['rows'], $data['decision'], $request->user(), $data['reason'] ?? null);

        return back()->with('success', 'سُجّل القرار على '.$result['decided'].' صف.'.($result['excluded']
            ? ' واستُبعد '.$result['excluded'].' صف: التعارضات وملاحظات الهوية والوصاية تُقبل صفًا صفًا، واختلاف اسم الوالد يُقبل داخل أسرة واحدة.' : ''));
    }

    /** «اعتماد مبدئي وإدراج في السجل» for every eligible row, or only for the selected rows' families. */
    public function approve(Request $request, string $publicId): RedirectResponse
    {
        $data = $request->validate(['rows' => 'nullable|array|max:5000', 'rows.*' => 'integer', 'confirm' => 'accepted'], ['confirm.accepted' => 'أكّد أن الاعتماد المبدئي ليس اعتمادًا قانونيًا للوصاية.']);
        $batch = $this->find($publicId);
        $this->pipeline->approve($batch, $request->user(), $data['rows'] ?? null);

        return back()->with('success', $batch->purpose === 'registry'
            ? 'بدأ الإدراج في السجل بحالة «معتمد مبدئيًا». الوصاية تُسجل ادعاءً لكل طفل بانتظار التحقق، ولا يظهر شيء في البوابة.'
            : 'اعتُمد التنفيذ. الكشوف تُسجل مسودات أو طلبات تعديل يعتمدها المدير التنفيذي.');
    }

    public function resume(Request $request, string $publicId): RedirectResponse
    {
        $state = $this->pipeline->resume($this->find($publicId), $request->user());

        return back()->with('success', 'استؤنف الفحص من الصف '.$state['resume_from'].' بعد تدقيق '.$state['staged'].' صفًا محفوظًا؛ الصفوف المحفوظة لا تُدرج مرتين.');
    }

    /** Re-analysis; erasing reviewed rows needs the head's explicit reset with a reason. */
    public function reanalyze(Request $request, string $publicId): RedirectResponse
    {
        $data = $request->validate(['reset' => 'boolean', 'reason' => 'nullable|string|max:500']);
        $this->pipeline->startAnalysis($this->find($publicId), $request->user(), (bool) ($data['reset'] ?? false), $data['reason'] ?? null);

        return back()->with('success', 'أُعيد الفحص الذكي.');
    }

    /** Drawer details of one staged row: provenance, findings, family members in the file, inferred guardian, result. */
    public function row(Request $request, string $publicId, int $row): JsonResponse
    {
        $this->access->assert($request->user(), 'import');
        $batch = $this->find($publicId);
        $identity = $this->access->allows($request->user(), 'identity');
        $r = DB::table('import_staged_rows')->where('batch_id', $batch->id)->where('id', $row)->firstOrFail();
        $event = DB::table('import_execution_events as x')->leftJoin('orphans as o', 'o.id', '=', 'x.orphan_id')->leftJoin('orphan_guardians as g', 'g.id', '=', 'x.guardian_id')
            ->leftJoin('households as h', 'h.id', '=', 'x.household_id')->where('x.row_id', $r->id)
            ->first(['x.outcome', 'x.message', 'x.created_at', 'o.public_id as orphan_public_id', 'o.reference_no as orphan_reference', 'o.status as orphan_status',
                'g.public_id as guardian_public_id', 'g.reference_no as guardian_reference', 'h.reference_no as household_reference']);
        $family = $r->family_key ? DB::table('import_staged_rows as s')->leftJoin('import_execution_events as x', 'x.row_id', '=', 's.id')->where('s.batch_id', $batch->id)
            ->where('s.family_key', $r->family_key)->orderBy('s.row_no')->limit(30)->get(['s.id', 's.row_no', 's.category', 's.normalized', 'x.outcome'])
            ->map(fn ($m) => ['id' => $m->id, 'row_no' => $m->row_no, 'category' => ImportPipeline::CATEGORIES[$m->category] ?? $m->category, 'outcome' => $m->outcome ? ImportPipeline::OUTCOMES[$m->outcome] : null,
                'child' => ($n = json_decode($m->normalized, true))['child_full_name'] ?: trim(($n['child_first_name'] ?? '').' '.($n['child_family_name'] ?? '')), 'guardian' => $n['guardian_full_name'] ?? '']) : [];
        $inference = json_decode((string) $r->inference, true);
        if ($inference) {
            $inference['guardian_id'] = $this->identity($inference['guardian_id'] ?? '', $identity);
            unset($inference['group_key'], $inference['contact_phone'], $inference['contact_phone_2']);
        }

        return response()->json([
            'row_no' => $r->row_no, 'sheet' => $batch->sheet, 'category' => ImportPipeline::CATEGORIES[$r->category] ?? $r->category,
            'family_basis' => match (substr((string) $r->family_key, 0, 3)) {
                'fm:' => 'هويتا الأب والأم', 'fn:' => 'هوية الأب واسم الأم', 'mn:' => 'هوية الأم واسم الأب', default => 'لا دليل كافٍ على أسرة (يُعامل الطفل منفردًا)'
            },
            'normalized' => $this->safeRow(json_decode($r->normalized, true), $identity), 'matches' => json_decode((string) $r->matches, true), 'inference' => $inference,
            'issues' => DB::table('import_row_issues')->where('row_id', $r->id)->get(['code', 'severity', 'field', 'message', 'resolution'])->map(fn ($i) => [...(array) $i, 'label' => ImportPolicy::label($i->code)]),
            'family' => $family, 'result' => $event ? [...(array) $event, 'outcome_label' => ImportPipeline::OUTCOMES[$event->outcome] ?? $event->outcome] : null,
        ]);
    }

    public function cancel(Request $request, string $publicId): RedirectResponse
    {
        $batch = $this->find($publicId);
        $this->access->assert($request->user(), 'import');
        abort_if(in_array($batch->status, ['executing', 'imported', 'completed', 'cancelled'], true), 409, 'لا تُلغى دفعة قيد التنفيذ أو مكتملة.');
        // The status change and its audit entry commit together (a request cut off midway leaves neither).
        DB::transaction(function () use ($batch, $request): void {
            DB::table('import_batches')->where('id', $batch->id)->update(['status' => 'cancelled', 'cancelled_by' => $request->user()->id, 'cancelled_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.import.cancelled', 'import_batches', $batch->id, ['status' => $batch->status, 'staged' => (int) $batch->rows_processed],
                null, $request->user()->id, $request->ip());
        });

        return back()->with('success', $batch->status === 'partially_imported' ? 'أُوقفت الدفعة؛ ما أُدرج منها يبقى في السجل «معتمد مبدئيًا»، والباقي لا يدخل.' : 'أُلغيت الدفعة؛ بياناتها المرحلية لا تدخل السجل.');
    }

    /** Row-level problems, permission-safe (masked IDs, no health text) and formula-injection safe. */
    public function errors(Request $request, string $publicId): StreamedResponse
    {
        $this->access->assert($request->user(), 'import');
        $batch = $this->find($publicId);
        DB::table('orphan_export_log')->insert(['user_id' => $request->user()->id, 'report' => 'import_errors', 'format' => 'csv', 'filters' => json_encode(['batch' => $batch->reference_no]),
            'rows' => DB::table('import_row_issues')->where('batch_id', $batch->id)->count(), 'personal_data' => false, 'ip_address' => $request->ip(), 'created_at' => now()]);

        return response()->streamDownload(function () use ($batch): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['الصف', 'التصنيف', 'الرمز', 'الحقل', 'الوصف', 'نتيجة التنفيذ'], ',', '"', '');
            DB::table('import_row_issues as i')->join('import_staged_rows as r', 'r.id', '=', 'i.row_id')->leftJoin('import_execution_events as x', 'x.row_id', '=', 'r.id')->where('i.batch_id', $batch->id)
                ->orderBy('r.row_no')->select(['r.row_no', 'r.category', 'i.code', 'i.field', 'i.message', 'x.outcome', 'x.message as exec'])->chunk(1000, function ($chunk) use ($out): void {
                    foreach ($chunk as $r) {
                        fputcsv($out, array_map([self::class, 'cell'], [$r->row_no, ImportPipeline::CATEGORIES[$r->category] ?? $r->category, $r->code, $r->field, $r->message, trim(($r->outcome ?? '').' '.($r->exec ?? ''))]), ',', '"', '');
                    }
                });
            DB::table('import_execution_events as x')->join('import_staged_rows as r', 'r.id', '=', 'x.row_id')->where('x.batch_id', $batch->id)->where('x.outcome', 'failed')->orderBy('r.row_no')
                ->select(['r.row_no', 'r.category', 'x.message'])->chunk(1000, function ($chunk) use ($out): void {
                    foreach ($chunk as $r) {
                        fputcsv($out, array_map([self::class, 'cell'], [$r->row_no, ImportPipeline::CATEGORIES[$r->category] ?? '', 'execution_failed', '', $r->message, 'failed']), ',', '"', '');
                    }
                });
            fclose($out);
        }, 'import-'.$batch->reference_no.'-issues.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    /** Neutralizes spreadsheet formulas in exported text (=, +, -, @, tab, CR). */
    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /** Staged values without health text, with masked identity numbers for readers without the «identity» capability. */
    private function safeRow(array $n, bool $identity): array
    {
        foreach (['child_id', 'guardian_id', 'father_id', 'mother_id'] as $k) {
            $n[$k] = $this->identity($n[$k] ?? '', $identity);
        }
        unset($n['health_note'], $n['father_death_cause'], $n['mother_death_cause'], $n['child_name_key'], $n['guardian_name_key'], $n['father_name_key'], $n['mother_name_key']);

        return $n;
    }

    private function identity(string $value, bool $identity): string
    {
        return $value !== '' && ! $identity ? Identity::mask(mb_substr($value, -4)) : $value;
    }

    private function find(string $publicId): object
    {
        return DB::table('import_batches')->where('public_id', $publicId)->firstOrFail();
    }
}
