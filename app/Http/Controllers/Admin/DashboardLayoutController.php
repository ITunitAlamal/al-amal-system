<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Configuration\NavigationAccessRules;
use App\Services\Dashboard\DashboardComponents;
use App\Services\Dashboard\DashboardLayoutResolver;
use App\Services\Dashboard\DashboardLayouts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «تخصيص اللوحات»: layout templates for the whole organization, a role, a department or one user, with version
 * history, restore, and a preview of any user's resolved layout and menu built from metadata only (no
 * impersonation, no records).
 */
class DashboardLayoutController extends Controller
{
    public function __construct(private readonly DashboardLayouts $layouts, private readonly DashboardLayoutResolver $resolver) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->isSystemAdmin(), 403);
        $users = DB::table('users')->pluck('name', 'id');
        $versions = DB::table('dashboard_layout_versions')->orderByDesc('version')->get()->groupBy('layout_id');
        $templates = DB::table('dashboard_layouts')->orderBy('scope_type')->orderBy('scope_key')->get()
            ->map(function (object $layout) use ($versions, $users): array {
                $history = ($versions[$layout->id] ?? collect())->map(fn (object $version): array => [
                    'version' => (int) $version->version, 'reason' => $version->change_reason, 'created_at' => $version->created_at,
                    'created_by' => $users[$version->created_by] ?? null, 'configuration' => json_decode($version->configuration, true),
                ])->values();

                return ['id' => (int) $layout->id, 'scope_type' => $layout->scope_type, 'scope_key' => $layout->scope_key,
                    'label' => $this->resolver->label($layout->scope_type, $layout->scope_key), 'is_active' => (bool) $layout->is_active,
                    'current_version' => (int) $layout->current_version,
                    'configuration' => $history->firstWhere('version', (int) $layout->current_version)['configuration'] ?? ['components' => []],
                    'history' => $history, 'updated_at' => $layout->updated_at];
            });

        return Inertia::render('Admin/DashboardLayouts/Index', [
            'templates' => $templates,
            'components' => DashboardComponents::all(),
            'sizes' => DashboardComponents::SIZES,
            'layers' => DashboardLayoutResolver::LAYERS,
            'navigation' => DB::table('navigation_items')->where('is_active', true)->orderBy('sort_order')
                ->get(['code', 'label_ar', 'route_name', 'access_rule', 'parent_id'])->map(fn (object $item): array => [
                    'code' => $item->code, 'label' => $item->label_ar, 'linkable' => $item->route_name !== null && ! str_starts_with($item->route_name, '@'),
                    'rule' => $item->access_rule])->values(),
            'roles' => DB::table('roles')->orderBy('name')->get(['code', 'name']),
            'departments' => DB::table('departments')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'is_active', 'parent_id']),
            'users' => DB::table('users')->orderBy('name')->get(['id', 'name', 'employee_number', 'is_active']),
            'urls' => ['save' => route('admin.dashboard-layouts.store', [], false), 'preview' => route('admin.dashboard-layouts.preview', [], false)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->layouts->save($request->user(), $request->all(), $request->ip());

        return back()->with('success', 'حُفظ القالب كإصدار جديد. يسري فورًا على من ينطبق عليهم، دون منح أي صلاحية.');
    }

    public function restore(Request $request, int $layout, int $version): RedirectResponse
    {
        $this->layouts->restore($request->user(), $layout, $version, (string) $request->input('reason', ''), $request->ip());

        return back()->with('success', 'استُرجع الإصدار كإصدار جديد؛ السجل السابق باقٍ.');
    }

    public function status(Request $request, int $layout): RedirectResponse
    {
        abort_unless($request->user()->isSystemAdmin(), 403);
        $input = $request->validate(['is_active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:500']], [], ['reason' => 'السبب']);
        $this->layouts->setActive($request->user(), $layout, (bool) $input['is_active'], $input['reason'], $request->ip());

        return back()->with('success', $input['is_active'] ? 'فُعّل القالب.' : 'عُطّل القالب؛ يعود أصحابه إلى القوالب الأدنى.');
    }

    /**
     * What a chosen user would get: applied templates, each component with its reason, shortcuts, hidden menu
     * items, landing page, and every menu item with whether the user may open it and why. Metadata only.
     */
    public function preview(Request $request, NavigationAccessRules $rules): JsonResponse
    {
        abort_unless($request->user()->isSystemAdmin(), 403);
        $input = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']], [], ['user_id' => 'المستخدم']);
        $user = User::findOrFail($input['user_id']);
        $resolved = $this->resolver->resolve($user);
        $registry = DashboardComponents::all();
        $components = [];
        foreach ($resolved['components'] as $key => $component) {
            $unavailable = $this->resolver->unavailableReason($user, $key);
            $components[] = ['key' => $key, 'label' => $registry[$key]['label'], 'configured' => $component['visible'], 'source' => $component['source'],
                'order' => $component['order'], 'size' => $component['size'], 'options' => $component['options'],
                'shown' => $component['visible'] && $unavailable === null,
                'reason' => ! $component['visible'] ? 'مخفي بالإعداد ('.$component['source'].').' : ($unavailable ?? 'يظهر.')];
        }
        $menu = DB::table('navigation_items')->where('is_active', true)->orderBy('sort_order')->get(['code', 'label_ar', 'access_rule', 'parent_id', 'route_name'])
            ->map(fn (object $item): array => ['code' => $item->code, 'label' => $item->label_ar, 'group' => $item->route_name === null,
                'allowed' => $rules->allows($user, (string) $item->access_rule), 'reason' => $rules->explain($user, (string) $item->access_rule),
                'hidden_by_template' => in_array($item->code, $resolved['hidden_navigation'], true)]);
        $roles = DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->leftJoin('departments as d', 'd.id', '=', 'a.department_id')
            ->where('a.user_id', $user->id)->get(['r.name as role', 'd.name as department'])
            ->map(fn (object $row): string => $row->role.' — '.($row->department ?? 'كل المؤسسة'));

        return response()->json([
            'user' => ['name' => $user->name, 'active' => (bool) $user->is_active, 'department' => DB::table('departments')->where('id', $user->department_id)->value('name'),
                'assignments' => $roles, 'heads' => DB::table('departments')->where('head_user_id', $user->id)->where('is_active', true)->pluck('name')],
            'applied' => $resolved['applied'],
            'components' => $components,
            'shortcuts' => $this->resolver->shortcuts($user) + ['source' => $resolved['shortcuts_source']],
            'hidden_navigation' => ['codes' => $resolved['hidden_navigation'], 'source' => $resolved['hidden_source']],
            'landing' => ['code' => $resolved['landing'], 'source' => $resolved['landing_source'], 'url' => $this->resolver->landingUrl($user)],
            'menu' => $menu,
        ]);
    }
}
