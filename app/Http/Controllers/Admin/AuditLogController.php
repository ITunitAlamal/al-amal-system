<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditScope;
use App\Services\Audit\AuditTaxonomy;
use App\Services\AuditService;
use App\Services\Configuration\AuditActionCatalog;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «سجل النظام»: the central audit register. Every list, detail, export and contextual history call is limited by
 * AuditScope on the server; the list never carries payloads, and details are masked by sensitivity and scope.
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 25;

    private const EXPORT_LIMIT = 20000;

    /** Subject types whose records have a page the reader may open (the page authorizes again). */
    private const SUBJECT_LINKS = [
        'workflow_instances' => ['workflow_instances', 'public_id', '/workflows/'],
        'import_batches' => ['import_batches', 'public_id', '/orphans/import/'],
        'campaigns' => ['campaigns', 'public_id', '/orphans/campaigns/'],
        'portal_applications' => ['portal_applications', 'public_id', '/orphans/reviews/'],
        'orphans' => ['orphans', 'public_id', '/orphans/registry/orphans/'],
        'orphan_guardians' => ['orphan_guardians', 'public_id', '/orphans/registry/guardians/'],
    ];

    public function __construct(private readonly AuditScope $scope, private readonly AuditActionCatalog $catalog) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($this->scope->allows($user), 403, 'ليس لديك صلاحية عرض سجل النظام.');
        $filters = $this->filters($request);
        $page = $this->query($request, $filters)
            ->orderByDesc('a.id')
            ->cursorPaginate(self::PER_PAGE, ['a.*', 'd.name as department_name'])
            ->withQueryString();
        $full = $this->scope->isFull($user);
        $stats = $this->scope->apply(DB::table('audit_logs as a'), $user)
            ->selectRaw("count(*) FILTER (WHERE a.occurred_at > now() - interval '24 hours') AS day")
            ->selectRaw("count(*) FILTER (WHERE a.outcome <> 'success' AND a.occurred_at > now() - interval '7 days') AS refused")
            ->selectRaw("count(*) FILTER (WHERE a.severity = 'critical' AND a.occurred_at > now() - interval '7 days') AS critical")
            ->first();

        return Inertia::render('Admin/AuditLogs/Index', [
            'rows' => collect($page->items())->map(fn (object $row): array => $this->row($row, $full))->all(),
            'cursor' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'filters' => $filters,
            'stats' => ['day' => (int) $stats->day, 'refused' => (int) $stats->refused, 'critical' => (int) $stats->critical],
            'chain' => $full ? $this->chainStatus() : null,
            'scope' => ['level' => $this->scope->for($user)['level']],
            'options' => [
                'modules' => AuditTaxonomy::MODULES, 'outcomes' => AuditTaxonomy::OUTCOMES, 'severities' => AuditTaxonomy::SEVERITIES,
                'sensitivities' => AuditTaxonomy::SENSITIVITIES, 'origins' => AuditTaxonomy::ORIGINS,
                'departments' => $full ? DB::table('departments')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']) : [],
                'actions' => $this->catalog->options($this->scope->apply(DB::table('audit_logs as a'), $user)
                    ->when($filters['module'], fn ($q, $m) => $q->where('a.module', $m))->distinct()->orderBy('a.action')->limit(400)->pluck('a.action')),
            ],
        ]);
    }

    /** Detail drawer: masked payload, request/correlation context and links the reader may open. */
    public function show(Request $request, string $eventId): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->scope->allows($user), 403, 'ليس لديك صلاحية عرض سجل النظام.');
        $row = $this->scope->apply(DB::table('audit_logs as a'), $user)->leftJoin('departments as d', 'd.id', '=', 'a.department_id')
            ->where('a.event_id', $eventId)->first(['a.*', 'd.name as department_name']);
        abort_unless($row, 404, 'الحدث غير موجود ضمن نطاقك.');
        $full = $this->scope->isFull($user);
        $metadata = json_decode((string) $row->metadata, true) ?: [];
        $hidden = $row->sensitivity === 'restricted' || ($row->sensitivity === 'personal' && ! $full);
        $related = $row->correlation_id ? $this->scope->apply(DB::table('audit_logs as a'), $user)->where('a.correlation_id', $row->correlation_id)
            ->where('a.id', '<>', $row->id)->orderBy('a.id')->limit(20)->get(['a.event_id', 'a.action', 'a.occurred_at', 'a.outcome'])
            ->map(fn (object $r): array => ['event_id' => $r->event_id, 'label' => $this->catalog->label($r->action), 'at' => $this->time($r->occurred_at), 'outcome' => $r->outcome])->all() : [];

        return response()->json([
            ...$this->row($row, $full),
            'details' => $hidden ? null : self::mask($metadata),
            'details_note' => $hidden ? ($row->sensitivity === 'restricted' ? 'تفاصيل هذا الحدث مقيدة (صحية أو مالية أو هوية)؛ يظهر ما حدث ومن نفّذه فقط.' : 'تفاصيل البيانات الشخصية تظهر للإدارة العليا فقط.') : null,
            'request' => ['route' => $row->route_name, 'method' => $row->request_method, 'correlation_id' => $row->correlation_id, 'guard' => $row->guard,
                'ip' => $row->ip_address ? ($full ? $row->ip_address : self::maskIp($row->ip_address)) : null],
            'integrity' => ['sealed' => $row->row_hash !== null, 'seq' => $row->seal_seq, 'hash' => $row->row_hash ? substr($row->row_hash, 0, 16).'…' : null],
            'related' => $related,
            'link' => $this->subjectLink($row->subject_type, $row->subject_id),
        ]);
    }

    /** CSV of the filtered list (no payloads, masked addresses), at most 20,000 rows; the export is itself audited. */
    public function export(Request $request, AuditService $audit): StreamedResponse
    {
        $user = $request->user();
        abort_unless($this->scope->allows($user), 403, 'ليس لديك صلاحية تصدير سجل النظام.');
        $filters = $this->filters($request);
        $query = $this->query($request, $filters)->select(['a.*', 'd.name as department_name']);
        $count = min(self::EXPORT_LIMIT, (clone $query)->count());
        $audit->event('audit.exported', ['subject_type' => 'audit_logs', 'severity' => 'notice', 'metadata' => ['filters' => array_filter($filters), 'rows' => $count, 'format' => 'csv'],
            'ip' => $request->ip()]);
        $full = $this->scope->isFull($user);

        return response()->streamDownload(function () use ($query, $full): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['معرف الحدث', 'التاريخ والوقت', 'المستخدم/المصدر', 'القسم', 'الوحدة', 'الإجراء', 'السجل المتأثر', 'المرجع', 'النتيجة', 'الأهمية', 'الحساسية', 'رقم الطلب المرتبط'], ',', '"', '');
            $written = 0;
            $query->chunkByIdDesc(1000, function ($rows) use ($out, $full, &$written): bool {
                foreach ($rows as $row) {
                    if (++$written > self::EXPORT_LIMIT) {
                        return false;
                    }
                    $r = $this->row($row, $full);
                    fputcsv($out, array_map([self::class, 'csvSafe'], [$r['event_id'], $r['at'], $r['actor'], $r['department'], $r['module_label'], $r['action_label'],
                        trim(($r['subject_label'] ?? '').' '.($r['subject_id'] ?? '')), $r['subject_ref'], $r['outcome_label'], $r['severity_label'], $r['sensitivity_label'], $r['correlation_id']]), ',', '"', '');
                }

                return true;
            }, 'a.id', 'id');
            fclose($out);
        }, 'audit-log-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** «سجل التغييرات» of one record, for the history panel inside that record's page. */
    public function history(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->scope->allows($user), 403, 'ليس لديك صلاحية عرض سجل التغييرات.');
        $data = $request->validate(['subject_type' => ['required', Rule::in(array_keys(self::SUBJECT_LINKS))], 'subject_id' => 'required|string|max:64']);
        [$table, $key] = self::SUBJECT_LINKS[$data['subject_type']];
        $id = DB::table($table)->where($key, $data['subject_id'])->value('id');
        abort_unless($id, 404);
        $full = $this->scope->isFull($user);
        // A campaign's history includes its beneficiary-list versions (drafts, submissions, executive decisions, amendments).
        $lists = $data['subject_type'] === 'campaigns' ? DB::table('campaign_lists')->where('campaign_id', $id)->pluck('id')->map(fn ($v): string => (string) $v)->all() : [];
        $rows = $this->scope->apply(DB::table('audit_logs as a'), $user)->leftJoin('departments as d', 'd.id', '=', 'a.department_id')
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('a.subject_type', $data['subject_type'])->where('a.subject_id', (string) $id))
                ->when($lists !== [], fn ($w) => $w->orWhere(fn ($x) => $x->where('a.subject_type', 'campaign_lists')->whereIn('a.subject_id', $lists))))
            ->orderByDesc('a.id')->limit(50)->get(['a.*', 'd.name as department_name']);

        return response()->json(['rows' => $rows->map(fn (object $row): array => $this->row($row, $full))->all()]);
    }

    /** @return array<string, string|null> */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'module' => ['nullable', Rule::in(array_keys(AuditTaxonomy::MODULES))], 'action' => ['nullable', 'string', 'max:120'],
            'outcome' => ['nullable', Rule::in(array_keys(AuditTaxonomy::OUTCOMES))], 'severity' => ['nullable', Rule::in(array_keys(AuditTaxonomy::SEVERITIES))],
            'sensitivity' => ['nullable', Rule::in(array_keys(AuditTaxonomy::SENSITIVITIES))], 'origin' => ['nullable', Rule::in(array_keys(AuditTaxonomy::ORIGINS))],
            'department' => ['nullable', 'integer'], 'correlation' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ], [], ['date_to' => 'تاريخ النهاية', 'date_from' => 'تاريخ البداية']);

        return array_map(fn ($v) => $v === null ? null : (string) $v, array_merge(array_fill_keys(['q', 'module', 'action', 'outcome', 'severity', 'sensitivity', 'origin', 'department', 'correlation', 'date_from', 'date_to'], null), $data));
    }

    private function query(Request $request, array $f): Builder
    {
        $full = $this->scope->isFull($request->user());

        return $this->scope->apply(DB::table('audit_logs as a'), $request->user())
            ->leftJoin('departments as d', 'd.id', '=', 'a.department_id')
            ->when($f['module'], fn ($q, $v) => $q->where('a.module', $v))
            ->when($f['action'], fn ($q, $v) => $q->where('a.action', $v))
            ->when($f['outcome'], fn ($q, $v) => $q->where('a.outcome', $v))
            ->when($f['severity'], fn ($q, $v) => $q->where('a.severity', $v))
            ->when($f['sensitivity'], fn ($q, $v) => $q->where('a.sensitivity', $v))
            ->when($f['origin'], fn ($q, $v) => $q->where('a.origin', $v))
            ->when($full && $f['department'], fn ($q) => $q->where('a.department_id', (int) $f['department']))
            ->when($f['correlation'], fn ($q, $v) => $q->where('a.correlation_id', $v))
            ->when($f['date_from'], fn ($q, $v) => $q->where('a.occurred_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($f['date_to'], fn ($q, $v) => $q->where('a.occurred_at', '<', Carbon::parse($v)->addDay()->startOfDay()))
            ->when($f['q'], function ($q, $v) {
                $term = '%'.addcslashes($v, '%_\\').'%';
                $q->where(fn ($w) => $w->where('a.actor_label', 'ilike', $term)->orWhere('a.action', 'ilike', $term)->orWhere('a.subject_ref', 'ilike', $term)
                    ->orWhere('a.subject_id', $v)->orWhere('a.event_id', preg_match('/^[0-9a-f\-]{36}$/i', $v) ? $v : '00000000-0000-0000-0000-000000000000'));
            });
    }

    private function row(object $row, bool $full): array
    {
        $actor = match ($row->actor_type) {
            'guardian' => 'وصي (حساب بوابة)',
            'worker' => 'مهمة خلفية',
            'system', null => $row->actor_id ? null : 'النظام',
            default => null,
        } ?? ($row->actor_label ?: 'حساب محذوف');

        return [
            'event_id' => $row->event_id, 'at' => $this->time($row->occurred_at), 'actor' => $actor, 'actor_type' => $row->actor_type,
            'origin_label' => AuditTaxonomy::ORIGINS[$row->origin] ?? $row->origin, 'department' => $row->department_name ?? null,
            'module' => $row->module, 'module_label' => AuditTaxonomy::MODULES[$row->module] ?? $row->module,
            'action' => $row->action, 'action_label' => $this->catalog->label($row->action),
            'subject_label' => $this->catalog->subjectLabel($row->subject_type) ?? $row->subject_type, 'subject_id' => $row->subject_id, 'subject_ref' => $row->subject_ref,
            'outcome' => $row->outcome, 'outcome_label' => AuditTaxonomy::OUTCOMES[$row->outcome] ?? $row->outcome,
            'severity' => $row->severity, 'severity_label' => AuditTaxonomy::SEVERITIES[$row->severity] ?? $row->severity,
            'sensitivity' => $row->sensitivity, 'sensitivity_label' => AuditTaxonomy::SENSITIVITIES[$row->sensitivity] ?? $row->sensitivity,
            'reason_code' => $row->reason_code, 'correlation_id' => $row->correlation_id,
        ];
    }

    private function time(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s') : null;
    }

    private function subjectLink(?string $type, ?string $id): ?string
    {
        if (! $type || ! $id || ! isset(self::SUBJECT_LINKS[$type]) || ! ctype_digit($id)) {
            return null;
        }
        [$table, $key, $prefix] = self::SUBJECT_LINKS[$type];
        $public = DB::table($table)->where('id', (int) $id)->value($key);

        return $public ? $prefix.$public : null;
    }

    private function chainStatus(): array
    {
        $last = Cache::get('audit.chain.last_verification');

        return ['unsealed' => DB::table('audit_logs')->whereNull('row_hash')->count(), 'sealed' => (int) DB::table('audit_logs')->max('seal_seq'),
            'verified' => $last ? ['ok' => $last['ok'], 'at' => $this->time($last['at']), 'checked' => $last['checked']] : null];
    }

    /** Second masking layer for older rows written before the central writer existed. */
    private static function mask(mixed $value, int $depth = 0): mixed
    {
        if (! is_array($value)) {
            return is_string($value) ? mb_substr($value, 0, 500) : $value;
        }
        if ($depth > 5) {
            return '…';
        }
        foreach ($value as $key => $item) {
            if (preg_match(AuditTaxonomy::SECRET_KEYS, (string) $key)) {
                $value[$key] = '[محجوب]';
            } elseif (preg_match(AuditTaxonomy::PROTECTED_KEYS, (string) $key)) {
                $value[$key] = $item === null || $item === '' ? null : '[تغيّر — بيانات محمية]';
            } else {
                $value[$key] = self::mask($item, $depth + 1);
            }
        }

        return $value;
    }

    private static function maskIp(string $ip): string
    {
        return str_contains($ip, '.') ? preg_replace('/\.\d+$/', '.x', $ip) : preg_replace('/:[^:]*$/', ':x', $ip);
    }

    private static function csvSafe(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $text) ? "'".$text : $text;
    }
}
