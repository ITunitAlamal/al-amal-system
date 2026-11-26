<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\Correspondence\CorrespondenceAccess;
use App\Services\Correspondence\CorrespondenceDocuments;
use App\Services\Correspondence\CorrespondenceRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class CorrespondencePolicyController extends Controller
{
    public function index(Request $request, CorrespondenceAccess $access, CorrespondenceRegistry $registry): Response
    {
        abort_unless($request->user()->is_active && $request->user()->isSystemAdmin(), 403);

        return Inertia::render('Correspondence/Policies', ['policies' => DB::table('correspondence_policies')->orderByDesc('id')->limit(100)->get(), 'settings' => $access->settings(), 'departments' => $access->departments()->values(), 'types' => $registry->constants('correspondence_types')->get(['v.id', 'v.name_ar']), 'assignments' => DB::table('department_user_roles as a')->join('users as u', 'u.id', '=', 'a.user_id')->join('roles as r', 'r.id', '=', 'a.role_id')->leftJoin('departments as d', 'd.id', '=', 'a.department_id')->whereIn('r.code', ['department_head', 'acting_department_head', 'executive_director', 'secretariat', 'board_secretary'])->get(['a.id', 'a.starts_on', 'a.ends_on', 'u.name', 'r.name as role_name', 'd.name as department_name'])]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        abort_unless($request->user()->is_active && $request->user()->isSystemAdmin(), 403);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['settings', 'cross_head', 'escalation'])], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'approval_reference' => ['required', 'string', 'max:200'], 'is_active' => ['required', 'boolean'],
            'head_after_hours' => ['required_if:kind,escalation', 'integer', 'min:1', 'max:8760'],
            'executive_after_hours' => ['required_if:kind,escalation', 'integer', 'gt:head_after_hours', 'max:17520'],
            'prefix' => ['required_if:kind,settings', 'regex:/^[A-Z0-9_-]{1,20}$/'], 'file_count' => ['required_if:kind,settings', 'integer', 'min:1', 'max:25'], 'file_kb' => ['required_if:kind,settings', 'integer', 'min:1', 'max:20480'], 'total_kb' => ['required_if:kind,settings', 'integer', 'min:1', 'max:102400'], 'allowed_extensions' => ['required_if:kind,settings', 'array', 'min:1'], 'allowed_extensions.*' => [Rule::in(array_keys(CorrespondenceDocuments::MIME))], 'letterhead' => ['required_if:kind,settings', 'string', 'max:500'], 'signature_label' => ['required_if:kind,settings', 'string', 'max:200'],
            'from_department_id' => ['required_if:kind,cross_head', 'integer', 'exists:departments,id'], 'to_department_id' => ['required_if:kind,cross_head', 'integer', 'different:from_department_id', 'exists:departments,id'], 'type_value_id' => ['required_if:kind,cross_head', 'integer'], 'purpose' => ['required_if:kind,cross_head', 'string', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $data, $audit): void {
            // Serialize policy versions for all administrators, including the first version of a key.
            DB::table('correspondence_sequences')->insertOrIgnore(['scope' => 'policy_versions', 'last_number' => 0]);
            DB::table('correspondence_sequences')->where('scope', 'policy_versions')->lockForUpdate()->first();
            if ($data['kind'] === 'settings') {
                abort_unless($data['is_active'] && empty($data['ends_on']) && (int) $data['total_kb'] >= (int) $data['file_kb'], 422);
                $key = 'system';
                $config = array_intersect_key($data, array_flip(['prefix', 'file_count', 'file_kb', 'total_kb', 'allowed_extensions', 'letterhead', 'signature_label'])) + ['confirmed' => true];
            } elseif ($data['kind'] === 'escalation') {
                $key = 'escalation';
                $config = array_intersect_key($data, array_flip(['head_after_hours', 'executive_after_hours']));
            } else {
                abort_unless(filled($data['ends_on'] ?? null), 422, 'تاريخ انتهاء الاستثناء مطلوب.');
                $registry = app(CorrespondenceRegistry::class);
                abort_unless($registry->constants('correspondence_types')->where('v.id', $data['type_value_id'])->exists(), 422);
                $class = $registry->constants('correspondence_classifications')->where('v.code', 'internal')->value('v.id');
                $key = 'cross:'.$data['from_department_id'].':'.$data['to_department_id'].':'.$data['type_value_id'];
                $config = array_intersect_key($data, array_flip(['from_department_id', 'to_department_id', 'type_value_id', 'purpose'])) + ['classification_value_id' => $class];
            }
            $version = (int) DB::table('correspondence_policies')->where('policy_key', $key)->max('version') + 1;
            $id = DB::table('correspondence_policies')->insertGetId(['policy_key' => $key, 'version' => $version, 'kind' => $data['kind'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null, 'approval_reference' => $data['approval_reference'], 'is_active' => $data['is_active'], 'configuration' => json_encode($config, JSON_THROW_ON_ERROR), 'created_by' => $request->user()->id, 'created_at' => now()]);
            $audit->recordRow('correspondence.policy.version', 'correspondence_policies', $id, null, ['policy_key' => $key, 'version' => $version, 'configuration' => $config, 'approval_reference' => $data['approval_reference']], (int) $request->user()->id);
        });

        return back();
    }

    public function appointment(Request $request, int $assignment, AuditService $audit): RedirectResponse
    {
        abort_unless($request->user()->is_active && $request->user()->isSystemAdmin(), 403);
        $data = $request->validate(['starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'decision_reference' => ['required', 'string', 'max:200']]);
        DB::transaction(function () use ($assignment, $data, $request, $audit): void {
            $row = DB::table('department_user_roles')->where('id', $assignment)->lockForUpdate()->first();
            abort_unless($row && $row->department_id, 422);
            $role = DB::table('roles')->where('id', $row->role_id)->value('code');
            abort_unless(in_array($role, ['department_head', 'acting_department_head', 'executive_director', 'secretariat', 'board_secretary'], true), 422);
            DB::table('department_user_roles')->where('id', $assignment)->update(['starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'], 'updated_at' => now()]);
            $audit->recordRow('correspondence.appointment.dates', 'department_user_roles', $assignment, (array) $row, $data, (int) $request->user()->id);
        });

        return back();
    }
}
