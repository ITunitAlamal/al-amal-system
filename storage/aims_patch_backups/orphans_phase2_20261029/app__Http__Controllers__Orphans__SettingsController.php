<?php

namespace App\Http\Controllers\Orphans;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Orphans\OrphanAccess;
use App\Services\Orphans\OrphanForms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module settings. Policy values (eligibility age, manual evidence review, sponsorship limits, campaign cooling-off)
 * are the executive director's decision; technical field-device values belong to the system admin; field rules and
 * document requirements are configured by the system admin as new versions, so earlier applications keep theirs.
 */
final class SettingsController extends Controller
{
    private const POLICY = ['eligibility_max_age', 'eligibility_age_status', 'manual_evidence_review_enabled', 'sponsorship_max_active_per_child', 'campaign_cooling_off_days', 'sponsorship_grace_days',
        'document_expiry_alert_days'];

    /** Data categories of the retention policy (Q50). */
    public const RETENTION_LABELS = [
        'child_file' => 'ملف اليتيم الأساسي', 'guardian_identity' => 'بيانات الوصي وهويته', 'documents' => 'المستندات المرفوعة', 'health' => 'البيانات الصحية',
        'social_visits' => 'البحث الاجتماعي والزيارات', 'sponsorship_records' => 'سجلات الكفالات والتعهدات', 'relief_delivery' => 'سجلات التوزيع والتسليم',
        'portal_requests' => 'طلبات البوابة والشكاوى', 'access_logs' => 'سجلات الاطلاع والتدقيق',
    ];

    public const RETENTION_STARTS = ['file_closed' => 'من إغلاق الملف', 'record_created' => 'من إنشاء السجل', 'last_activity' => 'من آخر نشاط'];

    public const RETENTION_ACTIONS = ['review' => 'مراجعة بشرية', 'anonymize' => 'إخفاء الهوية بعد المراجعة', 'archive' => 'أرشفة بعد المراجعة'];

    private const TECHNICAL = ['field_offline_max_hours', 'field_session_hours', 'field_lock_minutes'];

    public function __construct(private readonly OrphanAccess $access, private readonly AuditService $audit) {}

    public function show(Request $request, OrphanForms $forms): Response
    {
        $user = $request->user();
        abort_unless($user->isSystemAdmin() || $this->access->isExecutive($user) || $this->access->isHead($user), 403);
        $version = DB::table('orphan_form_versions')->where('status', 'active')->first();

        return Inertia::render('Orphans/Settings', [
            'settings' => DB::table('orphans_module_settings')->where('id', 1)->first(),
            'formVersion' => ['id' => $version->id, 'version' => $version->version, 'rules' => json_decode($version->rules, true), 'notes' => $version->notes,
                'activated_at' => $version->activated_at],
            'versions' => DB::table('orphan_form_versions')->orderByDesc('version')->get(['version', 'status', 'notes', 'activated_at']),
            'requirements' => DB::table('orphan_document_requirements as r')->join('master_data_values as v', 'v.id', '=', 'r.document_type_value_id')
                ->orderBy('r.applies_to')->orderBy('v.sort_order')->get(['r.id', 'r.applies_to', 'r.requirement', 'r.condition', 'r.note', 'r.is_active', 'v.name_ar as type']),
            'labels' => OrphanForms::LABELS,
            'retention' => Schema::hasTable('orphan_retention_policies') ? DB::table('orphan_retention_policies as p')->leftJoin('users as u', 'u.id', '=', 'p.updated_by')
                ->orderBy('p.sort_order')->get(['p.id', 'p.data_category', 'p.retention_months', 'p.starts_from', 'p.action_after', 'p.legal_basis', 'p.updated_at', 'u.name as updated_by'])
                ->map(fn (object $row): array => (array) $row + ['label' => self::RETENTION_LABELS[$row->data_category] ?? $row->data_category]) : [],
            'retentionOptions' => ['starts' => self::RETENTION_STARTS, 'actions' => self::RETENTION_ACTIONS],
            'can' => ['policy' => $this->access->isExecutive($user), 'technical' => $user->isSystemAdmin(), 'forms' => $user->isSystemAdmin()],
        ]);
    }

    /**
     * A retention period per data category (Q50), decided by the executive director. It is a recorded policy used to
     * review records; nothing is deleted or anonymized automatically.
     */
    public function updateRetention(Request $request, int $policy): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->access->isExecutive($user), 403, 'سياسة الاحتفاظ قرار للمدير التنفيذي.');
        $data = $request->validate([
            'retention_months' => ['nullable', 'integer', 'between:1,1200'],
            'starts_from' => ['required', Rule::in(array_keys(self::RETENTION_STARTS))],
            'action_after' => ['required', Rule::in(array_keys(self::RETENTION_ACTIONS))],
            'legal_basis' => ['nullable', 'required_with:retention_months', 'string', 'max:1000'],
        ], [], ['retention_months' => 'مدة الاحتفاظ', 'legal_basis' => 'السند أو المرجع']);
        DB::transaction(function () use ($request, $user, $policy, $data): void {
            $before = DB::table('orphan_retention_policies')->where('id', $policy)->lockForUpdate()->first();
            abort_unless($before, 404);
            DB::table('orphan_retention_policies')->where('id', $policy)->update($data + ['updated_by' => $user->id, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.retention.updated', 'orphan_retention_policies', $policy,
                Arr::only((array) $before, ['data_category', 'retention_months', 'starts_from', 'action_after', 'legal_basis']), $data, $user->id, $request->ip());
        });

        return back()->with('success', 'حُفظت سياسة الاحتفاظ لهذه الفئة. لا يُحذف أي سجل آليًا.');
    }

    public function updatePolicy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $executive = $this->access->isExecutive($user);
        abort_unless($executive || $user->isSystemAdmin(), 403);
        $rules = [];
        if ($executive) {
            $rules += ['eligibility_max_age' => ['required', 'integer', 'between:1,30'], 'eligibility_age_status' => ['required', 'in:pending_approval,approved'],
                'manual_evidence_review_enabled' => ['required', 'boolean'], 'sponsorship_max_active_per_child' => ['nullable', 'integer', 'between:1,20'],
                'campaign_cooling_off_days' => ['nullable', 'integer', 'between:1,730'], 'sponsorship_grace_days' => ['required', 'integer', 'between:0,365']];
            if (Schema::hasColumn('orphans_module_settings', 'document_expiry_alert_days')) {
                $rules['document_expiry_alert_days'] = ['required', 'integer', 'between:1,365'];
            }
        }
        if ($user->isSystemAdmin()) {
            $rules += ['field_offline_max_hours' => ['required', 'integer', 'between:1,168'], 'field_session_hours' => ['required', 'integer', 'between:1,24'],
                'field_lock_minutes' => ['required', 'integer', 'between:1,60']];
        }
        $data = Arr::only($request->validate($rules, [], ['field_offline_max_hours' => 'مدة العمل دون اتصال', 'document_expiry_alert_days' => 'مهلة التنبيه قبل انتهاء المستند']),
            array_merge($executive ? self::POLICY : [], $user->isSystemAdmin() ? self::TECHNICAL : []));
        DB::transaction(function () use ($request, $user, $data): void {
            $before = (array) DB::table('orphans_module_settings')->where('id', 1)->lockForUpdate()->first();
            DB::table('orphans_module_settings')->where('id', 1)->update($data + ['updated_by' => $user->id, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.settings.updated', 'orphans_module_settings', 1, Arr::only($before, array_keys($data)), $data, $user->id, $request->ip());
        });

        return back()->with('success', 'حُفظت الإعدادات وسُجل التغيير.');
    }

    /** Publishes a new version of the field rules; applications keep the version they were submitted under. */
    public function publishFormVersion(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isSystemAdmin(), 403);
        $data = $request->validate(['rules' => ['required', 'array'], 'notes' => ['required', 'string', 'min:5', 'max:1000']]);
        $current = json_decode((string) DB::table('orphan_form_versions')->where('status', 'active')->value('rules'), true);
        $locked = ['child' => ['first_name', 'father_name', 'grandfather_name', 'family_name', 'date_of_birth', 'sex', 'orphan_class_value_id'],
            'guardian' => ['name', 'identity_no', 'phone'], 'education' => ['school_year', 'enrollment_value_id'], 'health' => ['has_chronic', 'has_disability'],
            'father' => ['full_name', 'vital_status_value_id'], 'mother' => ['full_name', 'vital_status_value_id'], 'household' => ['family_name', 'original_address', 'current_address']];
        $next = $current;
        foreach ($data['rules'] as $form => $fields) {
            foreach ((array) $fields as $field => $rule) {
                if (! isset($current[$form][$field]) || in_array($field, $locked[$form] ?? [], true)) {
                    continue;
                }
                $req = $rule['req'] ?? 'optional';
                if (! in_array($req, ['required', 'optional', 'conditional', 'hidden'], true) || ($req === 'conditional' && empty($current[$form][$field]['when']))) {
                    continue;
                }
                $next[$form][$field]['req'] = $req;
            }
        }
        DB::transaction(function () use ($request, $user, $next, $data): void {
            $version = (int) DB::table('orphan_form_versions')->lockForUpdate()->max('version') + 1;
            DB::table('orphan_form_versions')->where('status', 'active')->update(['status' => 'retired', 'updated_at' => now()]);
            DB::table('orphan_form_versions')->insert(['version' => $version, 'rules' => json_encode($next, JSON_UNESCAPED_UNICODE), 'status' => 'active', 'notes' => $data['notes'],
                'created_by' => $user->id, 'activated_by' => $user->id, 'activated_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('orphans.forms.published', 'orphan_form_versions', $version, null, ['notes' => $data['notes']], $user->id, $request->ip());
        });

        return back()->with('success', 'نُشر إصدار جديد من قواعد الحقول؛ الطلبات السابقة تبقى على إصدارها.');
    }

    public function updateRequirement(Request $request, int $requirement): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isSystemAdmin(), 403);
        $data = $request->validate(['requirement' => ['required', 'in:required,optional'], 'is_active' => ['required', 'boolean']]);
        DB::transaction(function () use ($request, $user, $requirement, $data): void {
            $before = DB::table('orphan_document_requirements')->where('id', $requirement)->lockForUpdate()->first();
            abort_unless($before, 404);
            DB::table('orphan_document_requirements')->where('id', $requirement)->update($data + ['updated_by' => $user->id, 'updated_at' => now()]);
            $this->audit->recordRow('orphans.documents.requirement_updated', 'orphan_document_requirements', $requirement,
                ['requirement' => $before->requirement, 'is_active' => $before->is_active], $data, $user->id, $request->ip());
        });

        return back()->with('success', 'حُدّث متطلب المستند.');
    }
}
