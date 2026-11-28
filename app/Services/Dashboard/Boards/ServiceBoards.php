<?php

namespace App\Services\Dashboard\Boards;

use App\Models\User;
use App\Services\Configuration\SystemReadiness;
use App\Services\Finance\FinanceApprovalQueue;
use App\Services\Finance\FinanceGuard;
use App\Services\Hr\HrServicesDashboard;
use App\Services\Progress\TransactionTracker;
use App\Services\Rentals\RentalsDashboard;
use App\Support\SafeErrorMessage;
use Illuminate\Support\Facades\DB;

/**
 * Boards of the support departments (HR, accounts, assets and rentals), the system administrator and the employee.
 * Each one reuses the module's own service, so its numbers match the module pages.
 */
final class ServiceBoards
{
    public function __construct(private readonly TransactionTracker $tracker) {}

    public function hr(User $user): array
    {
        $hr = app(HrServicesDashboard::class);
        $today = now()->toDateString();
        $main = collect($hr->kpis($user, $today)['main'])->keyBy('key');
        $secondary = collect($hr->kpis($user, $today)['secondary'])->keyBy('key');
        $results = DB::table('attendance_daily_results');
        $leaves = DB::table('employee_leave_requests');
        $rows = array_map(fn (array $l): array => ['cells' => ['subject' => $l['employee'], 'type' => $l['type'].' · '.$l['department'], 'date' => $l['start_date'].' ← '.$l['end_date'], 'step' => $l['step'] ?: '—'],
            'status' => ['label' => $l['status'], 'tone' => 'info'], 'url' => $l['url'], 'action' => $l['mine'] ? 'إجراء' : 'متابعة', 'overdue' => false], $hr->pendingLeaves($user, 30));

        return [
            'key' => 'hr', 'title' => 'الموارد البشرية', 'decision' => 'أي مسائل الإجازات والدوام والموظفين تحتاج إجراء الإدارة؟', 'scope' => 'المؤسسة كلها',
            'hero' => BoardKit::metric('طلبات إجازة معلقة', (int) $main['leaves']['value'], 'down', [...BoardKit::weekOverWeek((clone $leaves)->whereNotNull('submitted_at'), 'submitted_at'), 'label' => 'قُدمت خلال آخر 7 أيام'],
                $main['leaves']['detail'], $main['leaves']['url']),
            'kpis' => [
                BoardKit::metric('الموظفون الفعّالون', (int) $main['employees']['value'], null, detail: $main['employees']['detail'], url: $main['employees']['url']),
                $secondary['review']['value'] === null ? BoardKit::unavailable('حالات حضور تحتاج مراجعة اليوم', $secondary['review']['detail'])
                    : BoardKit::metric('حالات حضور تحتاج مراجعة اليوم', (int) $secondary['review']['value'], 'down', detail: 'تأخير، خروج مبكر، بصمات ناقصة…', url: $secondary['review']['url']),
                BoardKit::metric('طلبات خدمات وصيانة مفتوحة', (int) $main['services']['value'], 'down', detail: $main['services']['detail'], url: $main['services']['url']),
            ],
            'trend' => BoardKit::trend('أيام الدوام أسبوعيًا: حضور مقابل حالات تحتاج مراجعة', 'من نتائج الدوام اليومية المحتسبة. الحالات = تأخير أو خروج مبكر أو بصمات ناقصة أو جدول غير مكتمل؛ البصمة الناقصة سؤال للمراجعة وليست غيابًا.', [
                ['label' => 'حضور', 'values' => BoardKit::weekly((clone $results)->whereIn('status', ['present', 'late', 'early', 'exempt_punch']), 'work_date')],
                ['label' => 'تحتاج مراجعة', 'values' => BoardKit::weekly((clone $results)->whereIn('status', HrServicesDashboard::ATTENDANCE_REVIEW), 'work_date')],
            ]),
            'bars' => BoardKit::bars('الموظفون الفعّالون حسب القسم', 'ملفات الموظفين الفعالة حسب القسم في الهيكل.',
                DB::table('employees as e')->leftJoin('departments as d', 'd.id', '=', 'e.department_id')->whereNull('e.deleted_at')->where('e.employment_status', 'active')
                    ->groupBy('d.name')->selectRaw("COALESCE(d.name, 'دون قسم') AS label, count(*) AS value")->get()->map(fn (object $r): array => ['label' => $r->label, 'value' => (int) $r->value])->all()),
            'table' => BoardKit::table('إجازات تنتظر الإجراء', [['key' => 'subject', 'label' => 'الموظف'], ['key' => 'type', 'label' => 'النوع والقسم'], ['key' => 'date', 'label' => 'الفترة'], ['key' => 'step', 'label' => 'المرحلة']],
                $rows, 'لا توجد إجازات معلقة.', route('leaves.index', [], false)),
            'actions' => [['label' => 'متابعة القسم', 'url' => route('hr.dashboard', [], false), 'icon' => 'chart'], ['label' => 'الدوام', 'url' => route('admin.attendance.index', [], false), 'icon' => 'calendar']],
        ];
    }

    public function finance(User $user): array
    {
        $queue = app(FinanceApprovalQueue::class);
        $approver = $queue->isApprover($user);
        $counts = $approver ? $queue->counts($user) : [];
        $entries = $approver ? $queue->entries($user, 30) : [];
        $guard = app(FinanceGuard::class);
        $kinds = array_values(array_filter(['payment', 'receipt'], fn (string $kind): bool => $guard->canViewTransactions($user, $kind)));
        $transactions = DB::table('finance_transactions')->whereIn('kind', $kinds);
        $executed = (clone $transactions)->whereNotNull('executed_at')->whereNull('reversal_of_id');
        $hasBooks = (clone $executed)->exists();
        $rows = array_map(fn (array $e): array => ['cells' => ['reference' => '#'.$e['id'], 'type' => $e['label'], 'amount' => $e['amount'] !== null ? number_format((float) $e['amount'], 2) : '—'],
            'status' => ['label' => 'بانتظار اعتمادك', 'tone' => 'warning'], 'url' => $e['url'], 'action' => 'مراجعة', 'overdue' => false], $entries);

        return [
            'key' => 'finance', 'title' => 'الحسابات', 'decision' => 'أي الاعتمادات والتسويات المالية تحتاج إجرائي؟', 'scope' => 'حسب صلاحياتك المالية',
            'hero' => $approver ? BoardKit::metric('اعتمادات مالية بانتظاري', array_sum($counts), 'down', detail: $counts ? collect($counts)->map(fn ($n, $l) => $l.' '.$n)->implode(' · ') : 'لا شيء بانتظارك', url: '/finance/approvals')
                : BoardKit::unavailable('اعتمادات مالية بانتظاري', 'لا تملك صلاحية اعتماد مالي.'),
            'kpis' => [
                $kinds ? BoardKit::metric('سندات منفذة هذا الشهر', (clone $executed)->where('executed_at', '>=', now()->startOfMonth())->count(), null, detail: 'ضمن صلاحياتك في القبض والصرف.', url: route('finance.index', [], false))
                    : BoardKit::unavailable('سندات منفذة هذا الشهر', 'لا تملك صلاحية الاطلاع على سندات القبض أو الصرف.'),
                $kinds ? BoardKit::metric('سندات بانتظار التنفيذ', (clone $transactions)->where('state', 'approved')->whereNull('executed_at')->count(), 'down', detail: 'ضمن صلاحياتك في القبض والصرف.')
                    : BoardKit::unavailable('سندات بانتظار التنفيذ', 'لا تملك صلاحية الاطلاع على سندات القبض أو الصرف.'),
                BoardKit::unavailable('الانحراف عن الموازنة المعتمدة', 'الدفاتر والموازنة تُمسك في النظام المحاسبي الخارجي؛ لا مصدر لها في AIMS.'),
            ],
            'trend' => BoardKit::trend('السندات المنفذة أسبوعيًا', 'عدد السندات المنفذة (دون العكسية). لا يُستنتج أي مبلغ من المساعدات المقترحة.', [
                ['label' => 'منفذة', 'values' => BoardKit::weekly(clone $executed, 'executed_at')],
            ], $hasBooks ? null : ($kinds ? 'لا توجد سندات منفذة ضمن صلاحياتك مسجلة في AIMS؛ الدفاتر تُمسك في النظام المحاسبي الخارجي.' : 'لا تملك صلاحية الاطلاع على سندات القبض أو الصرف.')),
            'bars' => BoardKit::bars('الاعتمادات المنتظرة حسب النوع', 'ما ينتظر اعتمادك حسب نوع المستند.', collect($counts)->map(fn (int $n, string $l): array => ['label' => $l, 'value' => $n])->values()->all(),
                $approver ? null : 'لا تملك صلاحية اعتماد مالي.'),
            'table' => BoardKit::table('مستندات تنتظر اعتمادي', [['key' => 'reference', 'label' => 'الرقم'], ['key' => 'type', 'label' => 'النوع'], ['key' => 'amount', 'label' => 'المبلغ']], $rows, 'لا توجد مستندات بانتظارك.', '/finance/approvals'),
            'actions' => [['label' => 'الحسابات', 'url' => route('finance.index', [], false), 'icon' => 'coins']],
        ];
    }

    public function rentals(User $user): array
    {
        $r = app(RentalsDashboard::class)->summary($user);
        $portfolio = collect($r['portfolio'])->keyBy('key');
        $maintenance = DB::table('workflow_instances as i')->join('workflow_versions as v', 'v.id', '=', 'i.workflow_version_id')->join('workflow_definitions as d', 'd.id', '=', 'v.workflow_definition_id')
            ->where('d.code', 'MAINTENANCE');
        $decisions = collect($r['decisions']['items'])->sum('count');
        $rows = array_map(fn (array $c): array => ['cells' => ['reference' => $c['number'], 'date' => $c['endsOn'], 'age' => $c['daysLeft'].' يوم'],
            'status' => $c['renewal'] ? ['label' => 'تجديد أو إنهاء قيد الإجراء', 'tone' => 'info'] : ['label' => 'دون إجراء تجديد', 'tone' => 'warning'], 'url' => $r['expiring']['url'], 'action' => 'فتح', 'overdue' => ! $c['renewal']],
            $r['expiring']['items']);

        return [
            'key' => 'rentals', 'title' => 'الأصول والمباني والإيجارات', 'decision' => 'أي العقود والاستحقاقات والصيانة تحتاج إجراء؟', 'scope' => 'وحدة الإيجارات',
            'hero' => BoardKit::metric('عقود تنتهي خلال '.$r['expiring']['days'].' يومًا دون إجراء تجديد', (int) $r['expiring']['withoutRenewal'], 'down', detail: 'من '.$r['expiring']['count'].' عقدًا تنتهي في المدة', url: $r['expiring']['url']),
            'kpis' => [
                BoardKit::metric('عقود فعالة', (int) $portfolio['active']['value'], null, detail: 'الأعيان: '.$portfolio['units']['value'].' · مؤجرة '.$portfolio['rented']['value'].' · شاغرة '.$portfolio['vacant']['value'], url: '/admin/rentals'),
                BoardKit::metric('عقود عليها استحقاقات متأخرة', (int) $r['overdue']['contracts'], 'down', detail: 'استحقاقات متأخرة: '.$r['overdue']['dues'], url: $r['overdue']['url']),
                BoardKit::metric($r['decisions']['title'], (int) $decisions, 'down', url: '/tasks'),
            ],
            'trend' => BoardKit::trend('طلبات الصيانة: المفتوحة مقابل المنجزة أسبوعيًا', 'من مسار طلبات الصيانة.', [
                ['label' => 'فُتحت', 'values' => BoardKit::weekly(clone $maintenance, 'i.started_at')],
                ['label' => 'أُنجزت', 'values' => BoardKit::weekly((clone $maintenance)->where('i.status', 'completed'), 'i.completed_at')],
            ]),
            'bars' => BoardKit::bars('الأعيان حسب الحالة', 'وحدات الأعيان المسجلة حسب حالة الإتاحة.', [
                ['label' => 'مؤجرة', 'value' => (int) $portfolio['rented']['value']], ['label' => 'شاغرة', 'value' => (int) $portfolio['vacant']['value']],
                ['label' => 'غير متاحة (صيانة/إيقاف)', 'value' => (int) $r['unavailableUnits']],
            ], $r['registered'] ? null : 'لم تُسجل أعيان بعد.'),
            'table' => BoardKit::table('عقود قريبة من الانتهاء', [['key' => 'reference', 'label' => 'العقد'], ['key' => 'date', 'label' => 'تاريخ الانتهاء'], ['key' => 'age', 'label' => 'المتبقي']], $rows, 'لا توجد عقود تنتهي قريبًا.', $r['expiring']['url']),
            'actions' => [['label' => 'الإيجارات', 'url' => '/admin/rentals', 'icon' => 'building']],
        ];
    }

    public function systemAdmin(User $user): array
    {
        $week = now()->subDays(7);
        $failedJobs = DB::table('failed_jobs');
        $syncFailed = DB::table('attendance_sync_runs')->where('status', 'failed');
        // A failed analysis, and an approved execution stopped by an error (it stays «executing» with the error set, OB-02).
        $imports = DB::table('import_batches')->where(fn ($q) => $q->where('status', 'failed')->orWhere(fn ($e) => $e->where('status', 'executing')->whereNotNull('error')));
        $devices = DB::table('attendance_devices')->where('is_active', true)->whereNotNull('last_error');
        // Stopped imports are listed below with their batch link, not a second time as a readiness gap.
        $gaps = collect(app(SystemReadiness::class)->checks())->where('ok', false)->where('code', '<>', 'import_batches');
        $rows = DB::table('failed_jobs')->orderByDesc('failed_at')->limit(10)->get(['uuid', 'queue', 'failed_at', 'exception'])
            ->map(fn (object $j): array => ['cells' => ['type' => 'مهمة خلفية فاشلة', 'subject' => mb_substr(strtok((string) $j->exception, "\n"), 0, 140), 'date' => (string) $j->failed_at],
                'status' => ['label' => 'فشل', 'tone' => 'danger'], 'url' => null, 'action' => null, 'overdue' => true])
            ->concat((clone $imports)->orderByDesc('updated_at')->limit(10)->get(['public_id', 'reference_no', 'status', 'error', 'updated_at'])
                ->map(fn (object $b): array => ['cells' => ['type' => ($b->status === 'executing' ? 'دفعة استيراد متوقفة أثناء الإدراج ' : 'دفعة استيراد فاشلة ').$b->reference_no,
                    'subject' => mb_substr((string) SafeErrorMessage::text($b->error), 0, 140), 'date' => (string) $b->updated_at],
                    'status' => ['label' => 'فشل', 'tone' => 'danger'], 'url' => '/orphans/import/'.$b->public_id, 'action' => 'فتح', 'overdue' => true]))
            ->concat((clone $devices)->get(['name', 'last_error', 'last_failure_at'])
                ->map(fn (object $d): array => ['cells' => ['type' => 'جهاز بصمة: '.$d->name, 'subject' => mb_substr((string) $d->last_error, 0, 140), 'date' => (string) $d->last_failure_at],
                    'status' => ['label' => 'خطأ اتصال', 'tone' => 'warning'], 'url' => route('admin.attendance.index', [], false), 'action' => 'فتح', 'overdue' => false]))
            ->concat($gaps->map(fn (array $g): array => ['cells' => ['type' => 'جاهزية الإعدادات', 'subject' => $g['title'].': '.implode('، ', $g['items']), 'date' => null],
                'status' => ['label' => 'ناقص', 'tone' => 'warning'], 'url' => $g['url'], 'action' => $g['action'], 'overdue' => false]))
            ->values()->all();

        return [
            'key' => 'sysadmin', 'title' => 'مدير النظام', 'decision' => 'هل توجد أعطال تقنية أو نواقص إعداد تحتاج تدخلي؟', 'scope' => 'النظام (الصلاحية التقنية لا تمنح اعتمادات المدير التنفيذي)',
            'hero' => BoardKit::metric('أعطال تقنية تحتاج متابعة', (clone $failedJobs)->count() + (clone $imports)->count() + (clone $devices)->count(), 'down',
                detail: 'مهام خلفية فاشلة '.(clone $failedJobs)->count().' · دفعات استيراد فاشلة أو متوقفة '.(clone $imports)->count().' · أجهزة بصمة بخطأ '.(clone $devices)->count()),
            'kpis' => [
                BoardKit::metric('نواقص جاهزية الإعدادات', $gaps->count(), 'down', detail: 'من '.count(app(SystemReadiness::class)->checks()).' فحصًا'),
                BoardKit::metric('مزامنات بصمة فاشلة (7 أيام)', (clone $syncFailed)->where('created_at', '>=', $week)->count(), 'down', url: route('admin.attendance.index', [], false)),
                BoardKit::metric('حسابات موظفين فعالة', DB::table('users')->where('is_active', true)->count()),
            ],
            'trend' => BoardKit::trend('الأعطال أسبوعيًا', 'مزامنات أجهزة البصمة الفاشلة والمهام الخلفية الفاشلة.', [
                ['label' => 'مزامنة فاشلة', 'values' => BoardKit::weekly(clone $syncFailed, 'created_at')],
                ['label' => 'مهمة خلفية فاشلة', 'values' => BoardKit::weekly(clone $failedJobs, 'failed_at')],
            ]),
            'bars' => BoardKit::bars('سجل التدقيق خلال 7 أيام حسب الوحدة', 'عدد العمليات المسجلة في سجل التدقيق مصنفة بأول جزء من رمز العملية.',
                DB::table('audit_logs')->where('created_at', '>=', $week)->selectRaw("split_part(action, '.', 1) AS label, count(*) AS value")->groupBy('label')->get()
                    ->map(fn (object $r): array => ['label' => $r->label, 'value' => (int) $r->value])->all()),
            'table' => BoardKit::table('أعطال ونواقص', [['key' => 'type', 'label' => 'المصدر'], ['key' => 'subject', 'label' => 'التفاصيل'], ['key' => 'date', 'label' => 'التاريخ']], $rows, 'لا توجد أعطال أو نواقص مسجلة.'),
            'actions' => [['label' => 'سجل التدقيق', 'url' => '/admin/audit-logs', 'icon' => 'list']],
        ];
    }

    public function employee(User $user): array
    {
        $tasks = $this->tracker->tasksOf($user);
        $requests = $this->tracker->overview($user, 1);
        $assigned = DB::table('workflow_events')->where('assigned_to_id', $user->id);
        $done = DB::table('workflow_events')->where('actor_id', $user->id)->whereNotIn('action', ['submit', 'start', 'comment']);
        $todo = DB::table('personal_tasks')->where('user_id', $user->id)->whereNull('completed_at');
        $rows = $tasks->take(40)->map(fn (array $t): array => ['cells' => ['reference' => $t['reference'], 'type' => $t['kind'], 'subject' => trim(($t['requester'] ?? '').' · '.$t['step'], ' ·'), 'date' => BoardKit::due($t['dueAt']) ?? '—'],
            'status' => $t['overdue'] ? ['label' => 'متأخر', 'tone' => 'danger'] : ['label' => 'بانتظار إجرائي', 'tone' => 'info'], 'url' => $t['url'], 'action' => 'فتح', 'overdue' => $t['overdue']])->values()->all();

        return [
            'key' => 'employee', 'title' => 'مهامي', 'decision' => 'ما الذي أنجزه اليوم؟', 'scope' => 'ما يخصني فقط',
            'hero' => BoardKit::metric('مهامي اليوم', $tasks->count(), 'down', [...BoardKit::weekOverWeek(clone $assigned, 'occurred_at'), 'label' => 'أُسندت إليك خلال آخر 7 أيام'], url: '/tasks'),
            'kpis' => [
                BoardKit::metric('مهام متأخرة', $tasks->where('overdue', true)->count(), 'down', url: '/tasks'),
                BoardKit::metric('طلباتي الجارية', (int) $requests['open'], null, url: route('requests.index', [], false)),
                BoardKit::metric('مهام شخصية مستحقة اليوم', $this->safeCount(fn () => (clone $todo)->whereDate('due_at', '<=', today())->count()), 'down', url: route('todo.index', [], false)),
            ],
            'trend' => BoardKit::trend('ما أُسند إليّ وما أنجزته أسبوعيًا', 'من مسارات العمل: المراحل المسندة إليك والإجراءات التي اتخذتها.', [
                ['label' => 'مسند', 'values' => BoardKit::weekly($assigned, 'occurred_at')],
                ['label' => 'منجز', 'values' => BoardKit::weekly($done, 'occurred_at')],
            ]),
            'bars' => BoardKit::bars('مهامي حسب النوع', 'المهام التي تنتظر إجراءك الآن.', $tasks->groupBy('kind')->map(fn ($g, $k): array => ['label' => $k, 'value' => $g->count()])->values()->all()),
            'table' => BoardKit::table('بانتظار إجرائي', [['key' => 'reference', 'label' => 'المرجع'], ['key' => 'type', 'label' => 'النوع'], ['key' => 'subject', 'label' => 'التفاصيل'], ['key' => 'date', 'label' => 'الاستحقاق']],
                $rows, 'لا توجد مهام بانتظارك.', '/tasks'),
            'actions' => [],
        ];
    }

    /** A personal To-Do column that differs between installs must not break the board. */
    private function safeCount(callable $count): ?int
    {
        try {
            return $count();
        } catch (\Throwable) {
            return null;
        }
    }
}
