<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Services\Orphans\Identity;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\RegistryQueries;
use App\Services\Orphans\Reports;
use App\Support\Reports\TabularXlsx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/** «التقارير والإحصائيات» with authorized, scoped and audited exports. */
final class ReportsController extends Controller
{
    public function __construct(private readonly OrphanAccess $access, private readonly Reports $reports, private readonly RegistryQueries $queries) {}

    public function index(Request $request): Response
    {
        $this->access->assertView($request->user());
        $filters = RegistryQueries::filters($request->query());
        $period = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'campaign' => 'nullable|integer', 'sponsor' => 'nullable|integer']);

        return Inertia::render('Orphans/Reports', [
            'filters' => [...$filters, 'from' => $period['from'] ?? null, 'to' => $period['to'] ?? null, 'campaign' => $period['campaign'] ?? null, 'sponsor' => $period['sponsor'] ?? null],
            'data' => $this->reports->build($filters, $period['from'] ?? null, $period['to'] ?? null),
            'donor' => ($period['campaign'] ?? null) || ($period['sponsor'] ?? null) ? $this->reports->donor($period['campaign'] ?? null, $period['sponsor'] ?? null) : null,
            'options' => $this->queries->options(), 'campaigns' => DB::table('campaigns')->orderByDesc('id')->get(['id', 'reference_no', 'title']),
            'sponsors' => DB::table('sponsors')->orderBy('name')->get(['id', 'name']), 'abilities' => $this->access->abilities($request->user()),
            'importUrl' => route('orphans.import.index', [], false),
        ]);
    }

    /**
     * Exports. «summary» and «donor» are aggregates; «registry» lists files and needs «export» plus a stated purpose;
     * identity numbers are masked unless the user also holds «identity». Every export is logged.
     */
    public function export(Request $request): HttpResponse
    {
        $this->access->assert($request->user(), 'export');
        $data = $request->validate(['report' => ['required', Rule::in(['summary', 'registry', 'donor'])], 'format' => ['required', Rule::in(['csv', 'xlsx'])], 'purpose' => 'nullable|string|max:300',
            'campaign' => 'nullable|integer', 'sponsor' => 'nullable|integer', 'from' => 'nullable|date', 'to' => 'nullable|date']);
        $filters = RegistryQueries::filters($request->query());
        $personal = $data['report'] === 'registry';
        if ($personal && blank($data['purpose'] ?? null)) {
            abort(422, 'اذكر الغرض من تصدير بيانات شخصية.');
        }
        [$title, $columns, $rows] = match ($data['report']) {
            'registry' => $this->registryRows($filters, $this->access->allows($request->user(), 'identity')),
            'donor' => $this->donorRows($this->reports->donor($data['campaign'] ?? null, $data['sponsor'] ?? null)),
            default => $this->summaryRows($this->reports->build($filters, $data['from'] ?? null, $data['to'] ?? null)),
        };
        DB::table('orphan_export_log')->insert(['user_id' => $request->user()->id, 'report' => $data['report'], 'format' => $data['format'], 'filters' => json_encode([...$filters, ...array_filter($data)], JSON_UNESCAPED_UNICODE),
            'rows' => count($rows), 'personal_data' => $personal, 'purpose' => $data['purpose'] ?? null, 'ip_address' => $request->ip(), 'created_at' => now()]);
        $name = 'orphans-'.$data['report'].'-'.now()->format('Ymd-His');
        if ($data['format'] === 'xlsx') {
            $path = TabularXlsx::create($title, 'أُعد في '.now()->format('Y-m-d H:i').' — '.$request->user()->name, array_map(fn ($c) => [$c, 'text'], $columns),
                array_map(fn ($r) => array_map(fn ($v) => ImportController::cell($v), $r), $rows), ['عدد الصفوف: '.count($rows)]);

            return response()->download($path, $name.'.xlsx', ['Cache-Control' => 'no-store, private'])->deleteFileAfterSend();
        }

        return response()->streamDownload(function () use ($columns, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, array_map([ImportController::class, 'cell'], $row), ',', '"', '');
            }
            fclose($out);
        }, $name.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }

    private function registryRows(array $filters, bool $identity): array
    {
        $rows = [];
        $this->queries->orphanQuery($filters)->select(['o.reference_no', 'o.status', 'o.completeness', 'p.full_name', 'p.sex', 'p.date_of_birth', 'p.id_last4', 'p.id_number', 'gv.name_ar as governorate', 'lv.name_ar as locality'])
            ->orderBy('o.id')->chunk(2000, function ($chunk) use (&$rows, $identity): void {
                foreach ($chunk as $r) {
                    $rows[] = [$r->reference_no, $r->full_name, $identity && $r->id_number ? \Illuminate\Support\Facades\Crypt::decryptString($r->id_number) : Identity::mask($r->id_last4), ['male' => 'ذكر', 'female' => 'أنثى'][$r->sex] ?? 'غير محدد',
                        $r->date_of_birth ?? '', $r->governorate ?? 'غير محدد', $r->locality ?? '', RegistryQueries::ORPHAN_STATUS[$r->status] ?? $r->status, $r->completeness.'%'];
                }
            });

        return ['سجل الأيتام (بيانات شخصية مقيدة)', ['رقم الملف', 'الاسم', 'رقم الهوية', 'الجنس', 'تاريخ الميلاد', 'المحافظة', 'المدينة', 'حالة الملف', 'الاكتمال'], $rows];
    }

    private function summaryRows(array $d): array
    {
        $rows = [['ملفات الأيتام القائمة', (string) $d['registry']['orphans']], ['ملفات معتمدة', (string) $d['registry']['approved']], ['ملفات مكتملة', (string) $d['registry']['complete']],
            ['الأوصياء النشطون', (string) $d['registry']['guardians']], ['الأيتام المكفولون', (string) $d['sponsorship']['orphans']],
            ['مستفيدون فريدون (أيتام) — تسليم مؤكد', (string) $d['benefits']['unique_orphans']], ['أسر مستفيدة فريدة — تسليم مؤكد', (string) $d['benefits']['unique_households']],
            ['عدد عمليات التسليم المؤكدة', (string) $d['benefits']['transactions']], ['إجمالي الوحدات المسلّمة', (string) $d['benefits']['units']]];
        foreach ($d['quality'] as $key => $value) {
            $rows[] = ['نسبة '.['valid_id' => 'الهوية صحيحة الصيغة', 'dob' => 'تاريخ الميلاد', 'sex' => 'الجنس', 'address' => 'العنوان الحالي', 'both_parents' => 'ربط الأب والأم', 'approved_guardian' => 'الوصاية المعتمدة'][$key],
                $value === null ? 'غير متاح' : $value.'%'];
        }
        foreach ($d['distribution']['geography'] as $g) {
            $rows[] = ['المحافظة: '.$g->label, (string) $g->n];
        }

        return ['ملخص قسم شؤون الأيتام والكفالات', ['المؤشر', 'القيمة'], $rows];
    }

    private function donorRows(array $d): array
    {
        $rows = [['مستفيدون فريدون', (string) $d['unique_beneficiaries']], ['عمليات التسليم', (string) $d['transactions']], ['الوحدات المسلّمة', (string) $d['units']]];
        foreach ($d['by_area'] as $a) {
            $rows[] = ['المنطقة: '.$a->area, $a->beneficiaries.' مستفيد / '.(float) $a->units.' وحدة'];
        }
        if ($d['sponsored_orphans'] !== null) {
            $rows[] = ['أيتام مكفولون حاليًا', (string) $d['sponsored_orphans']];
        }

        return ['تقرير المانح (بيانات مجمعة دون أسماء)', ['البند', 'القيمة'], $rows];
    }
}
