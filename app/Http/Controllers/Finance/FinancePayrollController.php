<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceGuard;
use App\Services\Finance\FinancePayrollRuns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** «مسير الرواتب والقسائم»: record the finalized external payroll, review and publish it (two different people). */
final class FinancePayrollController extends Controller
{
    private const READERS = ['finance.payroll.record', 'finance.payroll.publish', 'finance.payroll.authorize', 'finance.monitor'];

    public function __construct(private readonly FinanceGuard $guard, private readonly FinancePayrollRuns $runs) {}

    private function can(Request $request, string $permission): bool
    {
        return $this->guard->allows($request->user(), $permission);
    }

    public function index(Request $request): Response
    {
        abort_unless(collect(self::READERS)->contains(fn (string $p): bool => $this->can($request, $p)), 403, 'مسير الرواتب لمسؤول الرواتب ومن يراجعه ورئيس القسم.');
        $names = DB::table('users')->pluck('name', 'id');

        return Inertia::render('Finance/Payroll', [
            'runs' => DB::table('finance_payroll_batches')->orderByDesc('period')->orderByDesc('id')->limit(60)->get()->map(fn (object $b): array => [
                'id' => $b->id, 'period' => $b->period, 'currency' => $b->currency, 'state' => $b->state, 'stateLabel' => FinancePayrollRuns::STATES[$b->state] ?? $b->state,
                'source' => $b->source, 'sourceReference' => $b->source_reference ?? $b->hr_approval_reference, 'employees' => $b->employee_count,
                'gross' => $b->gross_total, 'deductions' => $b->deductions_total, 'net' => $b->total,
                'recordedBy' => $names[$b->created_by] ?? '—', 'recordedById' => (int) $b->created_by, 'createdAt' => (string) $b->created_at,
                'publishedBy' => $b->published_by ? ($names[$b->published_by] ?? '—') : null, 'publishedAt' => $b->published_at,
                'returnedReason' => $b->returned_reason, 'reviewedBy' => $b->reviewed_by ? ($names[$b->reviewed_by] ?? '—') : null, 'reviewedById' => (int) $b->reviewed_by,
                'approvedBy' => $b->approved_by ? ($names[$b->approved_by] ?? '—') : null,
                'requests' => DB::table('finance_payslip_requests')->where('period', $b->period)->whereIn('state', ['awaiting_payroll', 'not_included'])->count(),
            ]),
            'waiting' => DB::table('finance_payslip_requests')->whereIn('state', ['awaiting_payroll', 'not_included'])->select('period', DB::raw('count(*) as count'))
                ->groupBy('period')->orderByDesc('period')->get(),
            'currencies' => DB::table('currencies')->orderBy('code')->get(['code', 'name_ar']),
            'canRecord' => $this->can($request, 'finance.payroll.record'),
            'canPublish' => $this->can($request, 'finance.payroll.publish'),
            'canAuthorize' => $this->can($request, 'finance.payroll.authorize'),
            'canSeeLines' => $this->can($request, 'finance.payroll.record') || $this->can($request, 'finance.payroll.publish'),
            'userId' => (int) $request->user()->id,
            'externalSystem' => $this->guard->externalSystemName(),
            'maxRows' => FinancePayrollRuns::MAX_ROWS,
        ]);
    }

    /** Lines of one run, for the recorder and the reviewer only (head monitoring sees totals, not salaries). */
    public function show(Request $request, int $id): JsonResponse
    {
        abort_unless($this->can($request, 'finance.payroll.record') || $this->can($request, 'finance.payroll.publish'), 403);
        $batch = DB::table('finance_payroll_batches')->find($id);
        abort_unless($batch, 404);

        return response()->json(['lines' => DB::table('finance_payroll_items as i')->join('employees as e', 'e.id', '=', 'i.employee_id')
            ->leftJoin('finance_payslips as s', 's.payroll_item_id', '=', 'i.id')->where('i.batch_id', $id)->orderBy('i.source_row')->orderBy('i.id')
            ->get(['i.id', 'i.source_row', 'e.employee_number', 'e.full_name_ar', 'e.first_name_ar', 'e.family_name_ar', 'i.gross', 'i.deductions', 'i.net', 's.reference as slip'])
            ->map(fn (object $l): array => ['id' => $l->id, 'row' => $l->source_row, 'number' => $l->employee_number, 'name' => $l->full_name_ar ?: trim($l->first_name_ar.' '.$l->family_name_ar),
                'gross' => $l->gross, 'deductions' => $l->deductions, 'net' => $l->net, 'slip' => $l->slip])]);
    }

    public function upload(Request $request): JsonResponse
    {
        $this->guard->assert($request->user(), 'finance.payroll.record');
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:xlsx,csv,txt']], [], ['file' => 'ملف المسير']);

        return response()->json($this->runs->upload($request->file('file')));
    }

    public function inspect(Request $request): JsonResponse
    {
        $this->guard->assert($request->user(), 'finance.payroll.record');
        $d = $request->validate(['token' => 'required|string|size:64', 'format' => 'required|in:xlsx,csv', 'sheet' => 'nullable|string|max:120']);

        return response()->json($this->runs->inspect($d['token'], $d['format'], $d['sheet'] ?? null));
    }

    public function analyze(Request $request): JsonResponse
    {
        $this->guard->assert($request->user(), 'finance.payroll.record');
        $result = $this->runs->analyze($this->mapping($request));

        return response()->json(['count' => $result['count'], 'totals' => $result['totals'], 'errors' => array_slice($result['errors'], 0, 100),
            'errorCount' => count($result['errors']), 'sample' => array_slice(array_map(fn (array $l): array => ['row' => $l['row'], 'gross' => $l['gross'], 'deduction' => $l['deduction'], 'net' => $l['net']], $result['lines']), 0, 5)]);
    }

    public function store(Request $request): JsonResponse
    {
        $id = $this->runs->record($request->user(), $this->mapping($request, true));

        return response()->json(['id' => $id, 'message' => 'سُجّل المسير وأُرسل للمراجعة والنشر.'], 201);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $this->runs->review($request->user(), $id);

        return response()->json(['message' => 'رُوجع المسير وأُرسل لاعتماد المدير التنفيذي.']);
    }

    public function authorize(Request $request, int $id): JsonResponse
    {
        $this->runs->authorize($request->user(), $id);

        return response()->json(['message' => 'اعتُمد المسير؛ ينشره المراجع.']);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $this->runs->publish($request->user(), $id);

        return response()->json(['message' => 'نُشر المسير وأُصدرت القسائم.']);
    }

    public function return(Request $request, int $id): JsonResponse
    {
        // The stage decides the permission (reviewer before review, executive director before approval); checked first.
        $state = DB::table('finance_payroll_batches')->where('id', $id)->value('state');
        $this->guard->assert($request->user(), $state === 'pending_executive' ? 'finance.payroll.authorize' : 'finance.payroll.publish');
        $d = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $this->runs->return($request->user(), $id, $d['reason']);

        return response()->json(['message' => 'أُعيد المسير لمن أدخله.']);
    }

    /** @return array<string, mixed> */
    private function mapping(Request $request, bool $full = false): array
    {
        $d = $request->validate([
            'token' => 'required|string|size:64', 'format' => 'required|in:xlsx,csv', 'sheet' => 'nullable|string|max:120',
            'header_row' => 'required|integer|min:1|max:50', 'key_type' => 'required|in:employee_number,national_id',
            'key' => 'required|integer|min:1|max:500', 'net' => 'required|integer|min:1|max:500',
            'earnings' => 'required|array|min:1|max:60', 'earnings.*' => 'integer|min:1|max:500',
            'deductions' => 'nullable|array|max:60', 'deductions.*' => 'integer|min:1|max:500',
            'labels' => 'nullable|array|max:500', 'labels.*' => 'nullable|string|max:120',
            'period' => [$full ? 'required' : 'nullable', 'date_format:Y-m', 'before_or_equal:'.now()->format('Y-m')],
            'currency' => [$full ? 'required' : 'nullable', 'exists:currencies,code'],
            'source_reference' => [$full ? 'required' : 'nullable', 'string', 'min:3', 'max:120'],
            'file_name' => 'nullable|string|max:255',
        ], [], ['period' => 'شهر المسير', 'currency' => 'العملة', 'source_reference' => 'مرجع اعتماد المسير في البرنامج المحاسبي']);

        return [...$d, 'sheet' => $d['sheet'] ?? null, 'deductions' => array_map('intval', $d['deductions'] ?? []), 'earnings' => array_map('intval', $d['earnings']),
            'key' => (int) $d['key'], 'net' => (int) $d['net'], 'header_row' => (int) $d['header_row'], 'labels' => $d['labels'] ?? [],
            'source_reference' => isset($d['source_reference']) ? trim($d['source_reference']) : null];
    }
}
