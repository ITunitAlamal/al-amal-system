<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Attendance\AttendanceLedgerXlsx;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AttendanceEmployeeLedgerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->is_active && ($user->isSystemAdmin() || $user->isHrDepartmentHead() || $this->isExecutive((int) $user->id)), 403);

        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer', 'min:1', 'exists:employees,id'],
            'status' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $data['from']);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $data['to']);
        abort_if($from->diffInDays($to) > 30, 422, 'Maximum date range is 31 days.');

        $query = DB::table('attendance_daily_results as r')
            ->join('employees as e', 'e.id', '=', 'r.employee_id')
            ->whereBetween('r.work_date', [$data['from'], $data['to']])
            ->when(! empty($data['employee_id']), fn ($q) => $q->where('r.employee_id', (int) $data['employee_id']))
            ->when(! empty($data['status']), fn ($q) => $q->where('r.status', $data['status']))
            ->select(['r.id', 'r.employee_id', 'r.work_date', 'r.expected_in', 'r.expected_out', 'r.actual_in', 'r.actual_out', 'r.late_minutes', 'r.early_minutes', 'r.status', 'r.grace_minutes', 'e.employee_number', 'e.first_name_ar', 'e.family_name_ar']);

        $page = $query->orderByDesc('r.work_date')->orderBy('e.employee_number')->paginate(50);
        $tz = config('app.timezone', 'Asia/Gaza');
        $rows = $page->getCollection()->map(function ($row) use ($tz): array {
            $actualIn = $row->actual_in ? CarbonImmutable::parse($row->actual_in) : null;
            $actualOut = $row->actual_out ? CarbonImmutable::parse($row->actual_out) : null;
            $minutes = ($actualIn && $actualOut && $actualOut->greaterThanOrEqualTo($actualIn))
                ? (int) floor($actualIn->diffInSeconds($actualOut) / 60)
                : null;
            $fmt = static fn ($value) => $value ? CarbonImmutable::parse($value)->setTimezone($tz)->format('H:i') : null;

            return [
                'id' => $row->id, 'employee_id' => $row->employee_id,
                'employee_number' => $row->employee_number,
                'employee_name' => trim(($row->first_name_ar ?? '').' '.($row->family_name_ar ?? '')),
                'work_date' => $row->work_date,
                'expected_in' => $fmt($row->expected_in), 'expected_out' => $fmt($row->expected_out),
                'actual_in' => $fmt($row->actual_in), 'actual_out' => $fmt($row->actual_out),
                'attendance_minutes' => $minutes,
                'late_minutes' => $row->late_minutes, 'early_minutes' => $row->early_minutes,
                'status' => $row->status, 'grace_minutes' => $row->grace_minutes,
                'expected_in_instant' => $row->expected_in, 'actual_in_instant' => $row->actual_in, 'expected_out_instant' => $row->expected_out, 'actual_out_instant' => $row->actual_out,
            ];
        });
        // AIMS_LEDGER_COLORS_V1: display-only punctuality + auto-correction audit annotations.
        // Fetch annotations in one bounded query, never alter device punches or daily results.
        $employeeIds = $rows->pluck('employee_id')->unique()->values()->all();
        $annotations = [];
        if ($employeeIds && Schema::hasTable('attendance_auto_corrections')) {
            $localTz = config('app.timezone', 'Asia/Gaza');
            $startUtc = CarbonImmutable::parse($data['from'].' 00:00:00', $localTz)->subDay()->utc()->format('Y-m-d H:i:sP');
            $endUtc = CarbonImmutable::parse($data['to'].' 00:00:00', $localTz)->addDays(2)->utc()->format('Y-m-d H:i:sP');
            $corrections = DB::table('attendance_auto_corrections as ac')
                ->join('attendance_punches as ap', 'ap.id', '=', 'ac.punch_id')
                ->whereIn('ac.employee_id', $employeeIds)
                ->where('ap.occurred_at', '>=', $startUtc)
                ->where('ap.occurred_at', '<', $endUtc)
                ->orderBy('ac.id')->get([
                    'ac.id', 'ac.employee_id', 'ac.original_direction', 'ac.corrected_direction',
                    'ac.status', 'ac.reason_ar', 'ac.applied_at', 'ac.reverted_at',
                    'ac.reversion_reason', 'ap.occurred_at',
                ]);
            foreach ($corrections as $correction) {
                $day = CarbonImmutable::parse($correction->occurred_at)->setTimezone($localTz)->toDateString();
                if ($day < $data['from'] || $day > $data['to']) {
                    continue;
                }
                $key = $correction->employee_id.'|'.$day;
                $annotations[$key][] = [
                    'id' => $correction->id,
                    'status' => $correction->status,
                    'original_direction' => $correction->original_direction,
                    'corrected_direction' => $correction->corrected_direction,
                    'at' => CarbonImmutable::parse($correction->occurred_at)->setTimezone($localTz)->format('H:i'),
                    'note' => $correction->reason_ar,
                    'reversion_reason' => $correction->reversion_reason,
                ];
            }
        }
        $rows = $rows->map(function (array $row) use ($annotations): array {
            $row['auto_correction_notes'] = $annotations[$row['employee_id'].'|'.$row['work_date']] ?? [];
            $row['punctuality'] = 'neutral';
            $row['punctuality_label'] = 'غير محسوم';
            $row['actual_delay_minutes'] = null;
            // Incomplete / unknown data must never be green or red based on guesses.
            if (! empty($row['actual_in_instant']) && ! empty($row['expected_in_instant']) && $row['grace_minutes'] !== null) {
                $expected = CarbonImmutable::parse($row['expected_in_instant']);
                $actual = CarbonImmutable::parse($row['actual_in_instant']);
                $delaySeconds = max(0, $actual->getTimestamp() - $expected->getTimestamp());
                $graceMinutes = max(0, (int) $row['grace_minutes']);
                $row['actual_delay_minutes'] = (int) ceil($delaySeconds / 60);
                if ($delaySeconds === 0) {
                    $row['punctuality'] = 'on_time';
                    $row['punctuality_label'] = 'في الموعد';
                } elseif ($delaySeconds <= $graceMinutes * 60) {
                    $row['punctuality'] = 'grace_used';
                    $row['punctuality_label'] = 'ضمن السماح ('.(int) ceil($delaySeconds / 60).' دقيقة)';
                } else {
                    $row['punctuality'] = 'late';
                    $row['punctuality_label'] = 'تجاوز السماح ('.(int) ceil(($delaySeconds - $graceMinutes * 60) / 60).' دقيقة)';
                }
            }

            return $row;
        });
        // AIMS_LEDGER_LIVE_ENTRY_V1: show entry punctuality during an open working day.
        // A single outbound punch is only a potential entry: NO automatic correction is recorded here.
        $nowLocal = CarbonImmutable::now($tz);
        $todayLocal = $nowLocal->toDateString();
        $rows = $rows->map(function (array $row) use ($todayLocal, $nowLocal, $tz): array {
            $row['display_status'] = $row['status'];
            $row['potential_entry'] = false;
            $row['potential_entry_note'] = null;
            // A valid arrival can be classified regardless of whether the departure exists yet.
            if ($row['work_date'] === $todayLocal && ! empty($row['actual_in_instant'])
                && empty($row['actual_out_instant']) && $row['status'] === 'incomplete_punches') {
                $row['display_status'] = 'working';
            }
            if ($row['work_date'] !== $todayLocal || ! empty($row['actual_in_instant'])
                || empty($row['expected_in_instant']) || empty($row['expected_out_instant'])
                || $row['grace_minutes'] === null || $row['status'] !== 'incomplete_punches') {
                return $row;
            }
            $scheduledIn = CarbonImmutable::parse($row['expected_in_instant'])->setTimezone($tz);
            $scheduledOut = CarbonImmutable::parse($row['expected_out_instant'])->setTimezone($tz);
            if ($scheduledOut->toDateString() !== $todayLocal || $scheduledOut->lessThanOrEqualTo($scheduledIn)
                || $nowLocal->greaterThanOrEqualTo($scheduledOut)) {
                return $row;
            }
            $dayStart = CarbonImmutable::parse($todayLocal.' 00:00:00', $tz);
            $punches = DB::table('attendance_punches')
                ->where('employee_id', $row['employee_id'])
                ->where('occurred_at', '>=', $dayStart->format('Y-m-d H:i:sP'))
                ->where('occurred_at', '<', $dayStart->addDay()->format('Y-m-d H:i:sP'))
                ->orderBy('occurred_at')->limit(2)
                ->get(['id', 'direction', 'occurred_at', 'match_status']);
            if ($punches->count() !== 1) {
                return $row;
            }
            $p = $punches->first();
            if ($p->direction !== 'out' || ! in_array($p->match_status, ['matched', 'reviewed'], true)) {
                return $row;
            }
            if (DB::table('attendance_auto_corrections')->where('punch_id', $p->id)->exists()
                || DB::table('attendance_punch_corrections')->where('punch_id', $p->id)->whereIn('status', ['pending', 'approved'])->exists()) {
                return $row;
            }
            $time = CarbonImmutable::parse($p->occurred_at)->setTimezone($tz);
            if (abs($scheduledIn->getTimestamp() - $time->getTimestamp()) > 75 * 60 || $time->greaterThan($nowLocal)) {
                return $row;
            }
            $delaySeconds = max(0, $time->getTimestamp() - $scheduledIn->getTimestamp());
            $grace = max(0, (int) $row['grace_minutes']) * 60;
            $row['potential_entry'] = true;
            $row['potential_entry_note'] = 'اشتباه اختيار زر خروج بدل دخول عند '.$time->format('H:i').'؛ لم يُصحح النظام الحركة بعد، ويحتاج تأكيدًا من حركة لاحقة أو مراجعة إدارية.';
            $row['potential_entry_time'] = $time->format('H:i');
            $row['punctuality'] = $delaySeconds === 0 ? 'on_time' : ($delaySeconds <= $grace ? 'grace_used' : 'late');
            $row['punctuality_label'] = ($delaySeconds === 0 ? 'في الموعد' : ($delaySeconds <= $grace ? 'ضمن السماح' : 'تجاوز السماح')).' (مبدئي)';
            $row['display_status'] = 'potential_entry';

            return $row;
        });

        return response()->json([
            'data' => $rows, 'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(), 'total' => $page->total(),
        ]);
    }

    // AIMS_LEDGER_EXCEL_V1: export all filtered pages using the exact same source and permission rules as the ledger API.
    public function exportXlsx(Request $request): BinaryFileResponse
    {
        // Reuse index() validation, access control, computed color and auto-correction annotations.
        $rows = [];
        $page = 1;
        $maxRows = 10000;
        try {
            do {
                Paginator::currentPageResolver(static fn () => $page);
                $req = clone $request;
                $req->merge(['page' => $page]);
                $payload = json_decode($this->index($req)->getContent(), true, 512, JSON_THROW_ON_ERROR);
                foreach ($payload['data'] ?? [] as $row) {
                    $rows[] = $row;
                    abort_if(count($rows) > $maxRows, 422, 'Excel export exceeds 10,000 rows. Narrow the date range or employee filter.');
                }
                $last = (int) ($payload['last_page'] ?? 1);
                $page++;
            } while ($page <= $last);
        } finally {
            Paginator::currentPageResolver(static fn () => (int) request()->input('page', 1));
        }
        $path = AttendanceLedgerXlsx::create($rows, (string) $request->input('from'), (string) $request->input('to'));

        return response()->download($path, 'AIMS_Attendance_'.$request->input('from').'_to_'.$request->input('to').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
    }

    private function isExecutive(int $id): bool
    {
        return DB::table('department_user_roles as a')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.user_id', $id)->where('r.code', 'executive_director')
            ->where('u.is_active', true)->exists();
    }
}
