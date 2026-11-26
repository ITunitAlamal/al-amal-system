<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Orphans\Field\FieldDevices;
use App\Services\Orphans\Field\FieldKeys;
use App\Services\Orphans\Import\ImportPolicy;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\RegistryQueries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** «إعدادات القسم» (under «الإعدادات والصلاحيات»): staff capabilities and department policies. */
final class SettingsController extends Controller
{
    public const SETTINGS = [
        'orphan_max_age' => ['الحد الأقصى لعمر اليتيم (سنوات)', 'integer'],
        'allow_multiple_approved_guardians' => ['السماح بأكثر من وصاية معتمدة للطفل', 'boolean'],
        'followup_interval_days' => ['الفترة الافتراضية بين المتابعات (أيام)', 'integer'],
        'field_offline_max_hours' => ['أقصى مدة عمل دون اتصال لأجهزة التوزيع (ساعات، حتى 168)', 'integer'],
        'field_session_hours' => ['مدة جلسة جهاز التوزيع (ساعات)', 'integer'],
    ];

    public function __construct(private readonly OrphanAccess $access, private readonly AuditService $audit) {}

    public function index(Request $request): Response
    {
        abort_unless($this->access->canManageSettings($request->user()), 403);
        $grants = DB::table('orphan_staff_capabilities as c')->join('users as u', 'u.id', '=', 'c.user_id')->leftJoin('users as g', 'g.id', '=', 'c.granted_by')
            ->whereNull('c.revoked_at')->orderBy('u.name')->get(['c.id', 'c.user_id', 'c.capability', 'c.starts_on', 'c.ends_on', 'c.note', 'c.created_at', 'u.name', 'g.name as granted_by_name']);

        return Inertia::render('Orphans/Settings', [
            'capabilities' => OrphanAccess::CAPABILITIES, 'grants' => $grants, 'staff' => $this->access->staff(),
            'settings' => collect(self::SETTINGS)->map(fn (array $meta, string $key): array => ['key' => $key, 'label' => $meta[0], 'type' => $meta[1],
                'value' => json_decode((string) DB::table('orphan_settings')->where('key', $key)->value('value'), true)])->values(),
            'canGrant' => $request->user()->isSystemAdmin() || $this->access->isHead($request->user()),
            'department' => ['configured' => $this->access->departmentId() !== null, 'heads' => DB::table('users')->whereIn('id', $this->access->headUserIds())->pluck('name')],
            'devices' => DB::table('field_devices as d')->join('users as u', 'u.id', '=', 'd.user_id')->orderByRaw("CASE d.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")->orderByDesc('d.id')
                ->get(['d.id', 'd.name', 'd.status', 'd.platform', 'd.app_version', 'd.last_seen_at', 'd.last_sync_at', 'd.revoke_reason', 'u.name as user_name', DB::raw('substr(d.key_fingerprint, 1, 16) as fingerprint')]),
            'serverKey' => ['key_id' => app(FieldKeys::class)->keyId()],
            'canManageDevices' => $request->user()->isSystemAdmin() || $this->access->isHead($request->user()),
            'importPolicy' => ImportPolicy::matrix(),
        ]);
    }

    public function grant(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSystemAdmin() || $this->access->isHead($request->user()), 403, 'منح القدرات لرئيس القسم ومدير النظام.');
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id', 'capabilities' => 'required|array|min:1',
            'capabilities.*' => [Rule::in(array_keys(OrphanAccess::CAPABILITIES))], 'starts_on' => 'nullable|date', 'ends_on' => 'nullable|date|after_or_equal:starts_on', 'note' => 'nullable|string|max:500']);
        abort_unless(in_array((int) $data['user_id'], array_column($this->access->staff(), 'id'), true), 422, 'القدرات تُمنح لموظفي القسم فقط.');
        DB::transaction(function () use ($data, $request): void {
            foreach ($data['capabilities'] as $capability) {
                DB::table('orphan_staff_capabilities')->where('user_id', $data['user_id'])->where('capability', $capability)->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id, 'updated_at' => now()]);
                DB::table('orphan_staff_capabilities')->insert(['user_id' => $data['user_id'], 'capability' => $capability, 'starts_on' => $data['starts_on'] ?? null,
                    'ends_on' => $data['ends_on'] ?? null, 'note' => $data['note'] ?? null, 'granted_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->recordRow('orphans.capability.granted', 'users', (int) $data['user_id'], null, ['capabilities' => $data['capabilities']], $request->user()->id, $request->ip());
        });

        return back()->with('success', 'تم منح القدرات.');
    }

    public function revoke(Request $request, int $grant): RedirectResponse
    {
        abort_unless($request->user()->isSystemAdmin() || $this->access->isHead($request->user()), 403);
        $row = DB::table('orphan_staff_capabilities')->where('id', $grant)->whereNull('revoked_at')->first();
        abort_unless($row, 404);
        DB::table('orphan_staff_capabilities')->where('id', $grant)->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id, 'updated_at' => now()]);
        $this->audit->recordRow('orphans.capability.revoked', 'users', (int) $row->user_id, ['capability' => $row->capability], null, $request->user()->id, $request->ip());

        return back()->with('success', 'تم سحب القدرة.');
    }

    public function device(Request $request, int $device): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['approve', 'revoke', 'lost'])], 'reason' => 'nullable|string|max:300']);
        $devices = app(FieldDevices::class);
        $data['action'] === 'approve' ? $devices->approve($request->user(), $device) : $devices->revoke($request->user(), $device, $data['action'] === 'lost', (string) ($data['reason'] ?? ''));

        return back()->with('success', $data['action'] === 'approve' ? 'اعتُمد الجهاز.' : 'أُلغي الجهاز وانتهت جلساته.');
    }

    /** Smart-import policy matrix (configurable findings only; safety invariants stay fixed in code). */
    public function importPolicy(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isSystemAdmin() || $this->access->isHead($request->user()), 403, 'سياسة الاستيراد لرئيس القسم ومدير النظام.');
        $data = $request->validate(['levels' => 'required|array', 'levels.*' => [Rule::in(array_keys(ImportPolicy::LEVELS))]]);
        $value = ImportPolicy::sanitize($data['levels']);
        $before = DB::table('orphan_settings')->where('key', 'import_policy')->value('value');
        DB::table('orphan_settings')->updateOrInsert(['key' => 'import_policy'], ['value' => json_encode($value), 'updated_by' => $request->user()->id, 'updated_at' => now()]);
        $this->audit->recordRow('orphans.setting.updated', 'orphan_settings', 'import_policy', ['value' => $before], ['value' => $value], $request->user()->id, $request->ip());
        ImportPolicy::forget();

        return back()->with('success', 'حُفظت سياسة الاستيراد. تُطبق على الفحص القادم وعند إعادة فحص الصفوف.');
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($this->access->canManageSettings($request->user()), 403);
        $data = $request->validate(['orphan_max_age' => 'nullable|integer|min:1|max:30', 'allow_multiple_approved_guardians' => 'boolean', 'followup_interval_days' => 'nullable|integer|min:7|max:730', 'field_offline_max_hours' => 'nullable|integer|min:1|max:168', 'field_session_hours' => 'nullable|integer|min:1|max:24']);
        foreach (self::SETTINGS as $key => $meta) {
            if (array_key_exists($key, $data)) {
                $before = DB::table('orphan_settings')->where('key', $key)->value('value');
                DB::table('orphan_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($data[$key]), 'updated_by' => $request->user()->id, 'updated_at' => now()]);
                $this->audit->recordRow('orphans.setting.updated', 'orphan_settings', $key, ['value' => $before], ['value' => $data[$key]], $request->user()->id, $request->ip());
            }
        }
        RegistryQueries::forgetOptions();

        return back()->with('success', 'تم حفظ الإعدادات.');
    }
}
