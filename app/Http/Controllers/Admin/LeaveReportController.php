<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\EmployeeLeaveBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LeaveReportController extends Controller
{
    public function download(Request $r, EmployeeLeaveBalanceService $balance): StreamedResponse
    {
        abort_unless($r->user()?->canManageEmployees(), 403);
        $year = (int) $r->query('year', now()->year);
        abort_if($year < 2000 || $year > 2200, 422);
        app(AuditService::class)->event('leave.report.exported', ['subject_type' => 'employees', 'sensitivity' => 'personal', 'ip' => $r->ip(),
            'metadata' => ['year' => $year, 'employees' => DB::table('employees')->whereNull('deleted_at')->count(), 'format' => 'csv']]);

        return response()->streamDownload(function () use ($year, $balance) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['الرقم الوظيفي', 'اسم الموظف', 'نوع الإجازة', 'السنة', 'الرصيد الافتتاحي', 'الحركات', 'المتبقي', 'المحجوز', 'المتاح', 'افتتاحي مسجل']);
            $types = DB::table('master_data_values as v')->join('master_data_types as t', 't.id', '=', 'v.type_id')
                ->where('t.code', 'leave_types')->where('v.is_active', true)->where('t.is_active', true)->get(['v.id', 'v.name_ar']);
            foreach (DB::table('employees')->whereNull('deleted_at')->orderBy('id')->cursor() as $employee) {
                foreach ($types as $type) {
                    $b = $balance->summary($employee->id, $type->id, $year);
                    // Prevent Excel formula injection in imported employee/name fields.
                    $escape = static function ($v): string {
                        $str = (string) $v;

                        return preg_match('/^[\s]*[=+@\-]/u', $str) ? "'".$str : $str;
                    };
                    fputcsv($out, [
                        $escape($employee->employee_number), $escape(trim($employee->first_name_ar.' '.$employee->family_name_ar)),
                        $escape($type->name_ar), $year, $b['opening'], $b['movements'], $b['remaining'], $b['reserved'], $b['available'],
                        $b['opening_recorded'] ? 'نعم' : 'لا',
                    ]);
                }
            }
            fclose($out);
        }, 'AIMS_Leave_Balances_'.$year.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
