<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Hr\EmployeeAccess;
use App\Services\Hr\Import\EmployeeImportCommitter;
use App\Services\Hr\Import\EmployeeImportFields;
use App\Services\Hr\Import\EmployeeImportService;
use App\Services\Hr\Import\EmployeeImportTemplate;
use App\Services\Hr\Import\SpreadsheetReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * «استيراد الموظفين»: HR management (the appointed HR head or the system administrator) only. Pay and bank values in
 * the preview are masked unless the viewer holds their confidential permission.
 */
class EmployeeImportController extends Controller
{
    public function __construct(
        private readonly EmployeeImportService $imports,
        private readonly EmployeeAccess $access,
        private readonly AuditService $audit,
    ) {}

    private function guard(Request $request): void
    {
        abort_unless($request->user() && $this->access->canImport($request->user()), 403, 'استيراد الموظفين لرئيس الموارد البشرية ومدير النظام فقط.');
    }

    public function index(Request $request): Response
    {
        $this->guard($request);

        return Inertia::render('Admin/EmployeeImports/Index', [
            'batches' => DB::table('employee_import_batches as b')->join('users as u', 'u.id', '=', 'b.created_by')
                ->orderByDesc('b.id')->limit(50)
                ->get(['b.id', 'b.status', 'b.file_name', 'b.data_rows', 'b.summary', 'b.created_at', 'b.committed_at', 'b.reverted_at', 'u.name as created_by'])
                ->map(fn (object $batch): array => [...(array) $batch, 'summary' => json_decode((string) $batch->summary, true)]),
            'fields' => collect(EmployeeImportFields::FIELDS)->map(fn (array $field, string $key): array => ['key' => $key, 'ar' => $field[0], 'en' => $field[1], 'target' => $field[4]])->values(),
            'limits' => ['maxKb' => min(EmployeeImportService::MAX_FILE_KB, $this->phpUploadKb()), 'maxRows' => SpreadsheetReader::MAX_ROWS],
            'fileSettings' => $request->user()->isSystemAdmin() ? DB::table('hr_employee_file_settings')->where('id', 1)->first() : null,
            'phpUploadKb' => $this->phpUploadKb(),
        ]);
    }

    public function template(Request $request, EmployeeImportTemplate $template): BinaryFileResponse
    {
        $this->guard($request);

        return response()->download($template->build(), 'نموذج-استيراد-الموظفين.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard($request);
        $request->validate([
            'file' => ['required', 'file', 'max:'.min(EmployeeImportService::MAX_FILE_KB, $this->phpUploadKb()), 'extensions:xlsx,csv'],
        ], ['file.extensions' => 'الملف يجب أن يكون XLSX أو CSV.'], ['file' => 'ملف الموظفين']);
        $batchId = $this->imports->stage($request->file('file'), $request->user(), $request->ip());

        return redirect()->route('admin.employee-imports.show', $batchId)->with('success', 'قُرئ الملف ورُحّلت صفوفه مشفّرة للمراجعة. لم يُكتب شيء في ملفات الموظفين بعد.');
    }

    public function show(Request $request, int $batch): Response
    {
        $this->guard($request);
        $record = $this->imports->batch($batch);
        $user = $request->user();
        $filter = (string) $request->query('filter', 'all');

        return Inertia::render('Admin/EmployeeImports/Show', [
            'batch' => [
                'id' => (int) $record->id, 'status' => $record->status, 'file_name' => $record->file_name, 'sheet_name' => $record->sheet_name,
                'header_row' => (int) $record->header_row, 'data_rows' => (int) $record->data_rows, 'failure' => $record->failure,
                'created_at' => $record->created_at, 'committed_at' => $record->committed_at, 'reverted_at' => $record->reverted_at, 'revert_reason' => $record->revert_reason,
                'summary' => json_decode((string) $record->summary, true),
                'same_file' => DB::table('employee_import_batches')->where('file_sha256', $record->file_sha256)->where('id', '<>', $record->id)
                    ->whereIn('status', ['committed', 'reverted'])->pluck('id'),
            ],
            'columns' => collect(json_decode((string) $record->headers, true) ?: [])->map(fn (string $header, int|string $column): array => [
                'column' => (int) $column, 'letter' => $this->imports->columnLetter((int) $column), 'header' => $header,
                'field' => $this->imports->columnMap($record)[(int) $column] ?? null, 'suggested' => EmployeeImportFields::fieldForHeader($header),
            ])->values(),
            'fields' => collect(EmployeeImportFields::FIELDS)->map(fn (array $field, string $key): array => ['key' => $key, 'ar' => $field[0], 'en' => $field[1], 'target' => $field[4],
                'confidential' => EmployeeImportFields::isConfidential($key)])->values(),
            'options' => $this->imports->options($record),
            'choices' => [
                'currencies' => $this->imports->currencies(),
                'leaveTypes' => DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                    ->where('t.code', 'leave_types')->where('v.is_active', true)->orderBy('v.sort_order')->get(['v.id', 'v.name_ar']),
                'updateGroups' => EmployeeImportService::UPDATE_GROUPS,
            ],
            'references' => $this->imports->referenceSummary($record, $user),
            'rows' => $this->imports->previewRows($record, $user, $filter, max(1, $request->integer('page', 1))),
            'filter' => $filter,
            'issueCatalog' => collect(EmployeeImportFields::ISSUES)->map(fn (array $issue): array => ['level' => $issue[0], 'message' => $issue[1]]),
            'can' => [
                'edit' => in_array($record->status, ['staged', 'validated', 'failed'], true),
                'commit' => in_array($record->status, ['validated', 'failed'], true),
                'revert' => in_array($record->status, ['committed', 'failed'], true) && DB::table('employee_import_rows')->where('batch_id', $record->id)->whereIn('result', ['inserted', 'updated'])->exists(),
                'pay' => $this->access->canViewPay($user),
                'bank' => $this->access->canViewBank($user),
                'createValues' => $user->isSystemAdmin(),
            ],
        ]);
    }

    public function columns(Request $request, int $batch): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate(['map' => ['required', 'array'], 'map.*' => ['nullable', 'string', 'max:50']]);
        $this->imports->saveColumnMap($batch, $data['map'], $request->user(), $request->ip());

        return back()->with('success', 'حُفظ ربط الأعمدة وأُعيد التحقق من الصفوف.');
    }

    public function options(Request $request, int $batch): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'as_of_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after:2000-01-01'],
            'currency' => ['nullable', Rule::in($this->imports->currencies())],
            'percent_rule' => ['required', Rule::in(['review', 'fraction', 'literal'])],
            'zero_salary_unknown' => ['required', 'boolean'],
            'employee_number_from_attendance' => ['required', 'boolean'],
            'leave_type_value_id' => ['nullable', 'integer', Rule::exists('master_data_values', 'id')],
            'post_opening_balances' => ['required', 'boolean'],
        ], [], ['as_of_date' => 'تاريخ الأساس', 'currency' => 'العملة']);
        $this->imports->saveOptions($batch, $data, $request->user(), $request->ip());

        return back()->with('success', 'حُفظت خيارات الدفعة وأُعيد التحقق.');
    }

    public function values(Request $request, int $batch): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'field' => ['required', Rule::in(array_keys(EmployeeImportFields::REFERENCES))],
            'choices' => ['required', 'array', 'max:500'],
            'choices.*' => ['nullable', 'integer'],
            'remember' => ['required', 'boolean'],
        ]);
        $this->imports->saveDecisions($batch, $data['field'], $data['choices'], (bool) $data['remember'], $request->user(), $request->ip());

        return back()->with('success', 'حُفظت قرارات الربط وأُعيد التحقق.');
    }

    public function createValue(Request $request, int $batch): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'field' => ['required', Rule::in(array_keys(EmployeeImportFields::REFERENCES))],
            'key' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $this->imports->batch($batch);
        $id = $this->imports->createReferenceValue($data['field'], $data['name'], $request->user(), $request->ip());
        $this->imports->saveDecisions($batch, $data['field'], [$data['key'] => $id], true, $request->user(), $request->ip());

        return back()->with('success', 'أضيفت القيمة إلى ثوابت النظام وربطت بنص المصدر.');
    }

    public function row(Request $request, int $batch, int $row): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'action' => ['required', Rule::in(['insert', 'skip', 'update', 'reject'])],
            'groups' => ['nullable', 'array'],
            'groups.*' => ['string', Rule::in(array_keys(EmployeeImportService::UPDATE_GROUPS))],
        ]);
        $this->imports->setRowAction($batch, $row, $data['action'], $data['groups'] ?? [], $request->user(), $request->ip());

        return back(303)->with('success', 'حُدّث إجراء الصف.');
    }

    public function commit(Request $request, int $batch, EmployeeImportCommitter $committer): RedirectResponse
    {
        $this->guard($request);
        $request->validate(['confirm' => ['required', 'accepted']], ['confirm.accepted' => 'أكّد مراجعة الملخص قبل التنفيذ.']);
        $summary = $committer->commit($batch, $request->user(), $request->ip());
        $results = $summary['results'];

        return back()->with('success', "نُفذت الدفعة: أُنشئ {$results['inserted']} ملفًا، وحُدّث {$results['updated']}، وتُخطي {$results['skipped']}، ورُفض {$results['rejected']}. لم تُنشأ حسابات دخول ولم تُرسل إشعارات.");
    }

    public function revert(Request $request, int $batch, EmployeeImportCommitter $committer): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:2000']], [], ['reason' => 'سبب التراجع']);
        $summary = $committer->revert($batch, $request->user(), $data['reason'], $request->ip());
        $results = $summary['results'];

        return back()->with('success', "تم التراجع عن {$results['reverted']} صفًا؛ {$results['revert_blocked']} صفًا عُدّل بعد الاستيراد فبقي كما هو ويحتاج تصحيحًا يدويًا.");
    }

    public function cancel(Request $request, int $batch): RedirectResponse
    {
        $this->guard($request);
        DB::transaction(function () use ($request, $batch): void {
            $record = DB::table('employee_import_batches')->where('id', $batch)->lockForUpdate()->first();
            abort_unless($record, 404);
            if (! in_array($record->status, ['staged', 'validated'], true)) {
                throw ValidationException::withMessages(['batch' => 'لا يُلغى إلا دفعة لم تُنفذ.']);
            }
            DB::table('employee_import_batches')->where('id', $batch)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->audit->recordRow('hr.employee_import.cancelled', 'employee_import_batches', $batch, ['status' => $record->status], ['status' => 'cancelled'], $request->user()->id, $request->ip());
        });

        return redirect()->route('admin.employee-imports.index')->with('success', 'أُلغيت الدفعة دون أي أثر على ملفات الموظفين.');
    }

    public function issues(Request $request, int $batch): \Symfony\Component\HttpFoundation\Response
    {
        $this->guard($request);
        $record = $this->imports->batch($batch);
        $this->audit->recordRow('hr.employee_import.report_downloaded', 'employee_import_batches', $batch, null, ['report' => 'issues'], $request->user()->id, $request->ip());

        return response($this->imports->issueReportCsv($record), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="employee-import-'.$batch.'-issues.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Photo and document limits for employee files (system administrator). */
    public function settings(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isSystemAdmin(), 403);
        $data = $request->validate([
            'photo_max_kb' => ['required', 'integer', 'between:50,20480'],
            'photo_min_px' => ['required', 'integer', 'between:50,2000'],
            'photo_max_px' => ['required', 'integer', 'between:500,12000'],
            'document_max_kb' => ['required', 'integer', 'between:100,51200'],
            'employee_quota_mb' => ['required', 'integer', 'between:10,10240'],
            'self_photo_upload' => ['required', Rule::in(['disabled', 'review'])],
            'self_view_pay' => ['required', 'boolean'],
        ]);
        DB::transaction(function () use ($request, $data): void {
            $before = (array) DB::table('hr_employee_file_settings')->where('id', 1)->lockForUpdate()->first();
            DB::table('hr_employee_file_settings')->where('id', 1)->update([...$data, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            $this->audit->recordRow('hr.employee_files.settings', 'hr_employee_file_settings', 1, $before, $data, $request->user()->id, $request->ip());
        });

        return back()->with('success', 'حُفظت إعدادات ملفات الموظفين.');
    }

    private function phpUploadKb(): int
    {
        $bytes = function (string $value): int {
            $number = (int) $value;

            return match (strtolower(substr(trim($value), -1))) {
                'g' => $number * 1024 * 1024 * 1024,
                'm' => $number * 1024 * 1024,
                'k' => $number * 1024,
                default => $number,
            };
        };

        return (int) floor(min($bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))) / 1024);
    }
}
