<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceGuard;
use App\Services\Finance\FinanceReports;
use App\Support\Reports\TabularXlsx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reports of «قسم الحسابات»: the on-screen preview and the Excel, PDF, CSV and print exports. Every request is
 * authorized again on the server, so a revoked permission blocks an export even from a tab that is still open.
 */
final class FinanceReportsController extends Controller
{
    public function __construct(private readonly FinanceGuard $guard, private readonly FinanceReports $reports) {}

    public function index(Request $request): JsonResponse
    {
        $this->guard->assert($request->user(), 'finance.reports.view');

        return response()->json(['reports' => $this->reports->available($request->user()), 'areas' => FinanceReports::AREAS]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        abort_unless($this->reports->canView($request->user(), $report), 403, 'لا تملك صلاحية هذا التقرير');
        $definition = $this->reports->definition($report);
        $filters = $this->reports->filters($report, $request->query());
        $page = $this->reports->query($report, $filters)->paginate(50);

        return response()->json([
            'title' => $definition['title'],
            'columns' => collect($definition['columns'])->map(fn (array $column, string $key): array => ['key' => $key, 'label' => $column[0], 'type' => $column[1]])->values(),
            'rows' => $this->reports->present($report, collect($page->items())),
            'page' => ['current' => $page->currentPage(), 'last' => $page->lastPage(), 'total' => $page->total()],
            'totals' => $this->reports->totals($report, $filters),
            'filters' => $this->reports->describeFilters($filters),
            'export' => $this->reports->canExport($request->user(), $report),
            'max_rows' => FinanceReports::MAX_ROWS,
        ]);
    }

    public function export(Request $request, string $report, string $format): Response
    {
        $user = $request->user();
        if (! $this->reports->canExport($user, $report)) {
            $this->guard->audit('report', $report, (int) $user->id, 'export_denied', ['format' => $format]);
            abort(403, 'لا تملك صلاحية تصدير هذا التقرير');
        }
        $definition = $this->reports->definition($report);
        $filters = $this->reports->filters($report, $request->query());
        $totals = $this->reports->totals($report, $filters);
        abort_if($totals['count'] > FinanceReports::MAX_ROWS, 422, 'نتائج التقرير تتجاوز '.FinanceReports::MAX_ROWS.' سجل؛ ضيّق الفترة أو التصفية ثم أعد التصدير.');
        $rows = $this->reports->query($report, $filters)->limit(FinanceReports::MAX_ROWS)->get();
        $this->guard->audit('report', $report, (int) $user->id, 'export', ['format' => $format, 'filters' => $filters, 'rows' => $rows->count()]);

        $columns = collect($definition['columns'])->map(fn (array $column): array => [$column[0], $column[1]])->values()->all();
        $generated = now()->format('Y-m-d H:i');
        $meta = 'تاريخ الإنشاء: '.$generated.' · بواسطة: '.$user->name.' · '.$this->reports->describeFilters($filters);
        $footer = ['عدد السجلات: '.$totals['count'], ...array_map(fn (array $money): string => 'الإجمالي ('.$money['currency'].'): '.$money['amount'], $totals['money'])];
        $filename = 'AIMS_'.$report.'_'.now()->format('Ymd_His');

        if ($format === 'csv') {
            $cells = $this->reports->present($report, $rows, true);

            return response()->streamDownload(function () use ($columns, $cells): void {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, array_column($columns, 0));
                foreach ($cells as $row) {
                    // Spreadsheet formula injection: a leading = + @ - (or tab) is neutralised.
                    fputcsv($out, array_map(fn (string $value): string => preg_match('/^[=+@\-\t]/u', $value) && ! is_numeric($value) ? "'".$value : $value, array_values($row)));
                }
                fclose($out);
            }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
        }

        if ($format === 'xlsx') {
            $cells = array_map(fn (array $row): array => array_map(fn (string $value): ?string => $value === '' ? null : $value, array_values($row)),
                $this->reports->present($report, $rows, true));
            $path = TabularXlsx::create($definition['title'], $meta, $columns, $cells, $footer, FinanceReports::AREAS[$definition['area']]);

            return response()->download($path, $filename.'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'no-store, private',
            ])->deleteFileAfterSend();
        }

        // «PDF» and «طباعة»: the same document, laid out for A4 right to left; the browser prints or saves it as PDF.
        return response()->view('finance.report-print', [
            'title' => $definition['title'], 'area' => FinanceReports::AREAS[$definition['area']], 'generated' => $generated, 'actor' => $user->name,
            'filters' => $this->reports->describeFilters($filters), 'columns' => $columns,
            'rows' => $this->reports->present($report, $rows), 'footer' => $footer, 'pdf' => $format === 'pdf',
        ])->withHeaders(['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}
