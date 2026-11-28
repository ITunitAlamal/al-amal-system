<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\Configuration\NavigationAccessRules;
use App\Services\Configuration\NavigationBuilder;
use App\Services\Finance\FinanceApprovalQueue;
use App\Services\Finance\FinanceGuard;
use App\Services\Hr\HrServicesDashboard;
use App\Services\Organization\Authority;
use App\Services\Organization\EffectiveAssignments;
use App\Services\Rentals\RentalsDashboard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Works out a user's home layout from the templates that apply to them, in a fixed precedence:
 * built-in defaults < organization < role < department < user. Several templates on the same level (several
 * roles or departments) are merged: a component shows when any of them shows it, takes the smallest order and
 * the widest size; shortcut lists are united; a menu item is hidden only when every template on that level that
 * lists hidden items hides it; the landing page comes from the first template (by role code or department id).
 * A higher level replaces what it sets. The result is layout only: availability() decides, from the user's own
 * access, whether each component may appear at all.
 */
final class DashboardLayoutResolver
{
    public const LAYERS = ['organization' => 'القالب العام للمؤسسة', 'role' => 'قالب الدور', 'department' => 'قالب القسم', 'user' => 'تخصيص المستخدم'];

    /** @var array<int, array<string, mixed>> */
    private array $resolved = [];

    public function __construct(private readonly NavigationAccessRules $rules) {}

    /**
     * Active templates that apply to the user, by level, each with its current configuration.
     *
     * @return array<string, list<array{id: int, scope_type: string, scope_key: string, label: string, version: int, configuration: array<string, mixed>}>>
     */
    public function templatesFor(User $user): array
    {
        // The same role codes as the menu: the executive director and department head codes follow Organization\Authority.
        $roles = app(NavigationBuilder::class)->roleCodes($user);
        $own = collect([$user->department_id])
            ->merge(EffectiveAssignments::apply(DB::table('department_user_roles')->where('user_id', $user->id)->whereNotNull('department_id'))->pluck('department_id'))
            ->merge(app(Authority::class)->headedDepartmentIds($user))
            ->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $parents = DB::table('departments')->whereNull('deleted_at')->pluck('parent_id', 'id')->all();
        $ancestors = [];
        foreach ($own as $id) {
            $seen = [];
            for ($parent = $parents[$id] ?? null; $parent !== null && ! isset($seen[$parent]); $parent = $parents[$parent] ?? null) {
                $seen[$parent] = true;
                $ancestors[] = (int) $parent;
            }
        }

        $rows = DB::table('dashboard_layouts as l')
            ->join('dashboard_layout_versions as v', fn ($join) => $join->on('v.layout_id', '=', 'l.id')->on('v.version', '=', 'l.current_version'))
            ->where('l.is_active', true)
            ->where(fn ($scope) => $scope->where('l.scope_type', 'organization')
                ->orWhere(fn ($role) => $role->where('l.scope_type', 'role')->whereIn('l.scope_key', $roles === [] ? [''] : $roles))
                ->orWhere(fn ($department) => $department->where('l.scope_type', 'department')
                    ->whereIn('l.scope_key', array_map('strval', array_unique([...$own, ...$ancestors, 0]))))
                ->orWhere(fn ($person) => $person->where('l.scope_type', 'user')->where('l.scope_key', (string) $user->id)))
            ->get(['l.id', 'l.scope_type', 'l.scope_key', 'v.version', 'v.configuration']);

        $layers = array_fill_keys(array_keys(self::LAYERS), []);
        foreach ($rows as $row) {
            $configuration = json_decode($row->configuration, true) ?: [];
            if ($row->scope_type === 'department' && ! in_array((int) $row->scope_key, $own, true) && empty($configuration['include_subunits'])) {
                continue;
            }
            $layers[$row->scope_type][] = ['id' => (int) $row->id, 'scope_type' => $row->scope_type, 'scope_key' => (string) $row->scope_key,
                'label' => $this->label($row->scope_type, (string) $row->scope_key), 'version' => (int) $row->version, 'configuration' => $configuration];
        }
        foreach ($layers as $layer => $templates) {
            usort($templates, fn (array $a, array $b): int => $layer === 'department' ? (int) $a['scope_key'] <=> (int) $b['scope_key'] : strcmp($a['scope_key'], $b['scope_key']));
            $layers[$layer] = $templates;
        }

        return $layers;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(User $user): array
    {
        if (isset($this->resolved[(int) $user->id])) {
            return $this->resolved[(int) $user->id];
        }
        $layout = $this->merge($this->templatesFor($user));
        $roles = app(NavigationBuilder::class)->roleCodes($user);
        $layout['executive_workspace'] = $user->is_active && in_array('executive_director', $roles, true) && ! in_array('system_admin', $roles, true);
        if ($layout['executive_workspace']) {
            $hidden = ['hr_group', 'rentals', 'attendance', 'attendance_compensatory', 'attendance_operations', 'leaves_balances', 'my_department'];
            $items = DB::table('navigation_items')->get(['id', 'parent_id', 'code']);
            do {
                $previous = count($hidden);
                $parents = $items->whereIn('code', $hidden)->pluck('id')->all();
                $hidden = array_values(array_unique([...$hidden, ...$items->whereIn('parent_id', $parents)->pluck('code')->all()]));
            } while (count($hidden) !== $previous);
            $layout['hidden_navigation'] = array_values(array_unique([...$layout['hidden_navigation'], ...$hidden]));
            foreach (['team', 'hr_services', 'rentals', 'evaluation', 'activity'] as $key) {
                $layout['components'][$key]['visible'] = false;
            }
            foreach (['executive', 'tasks', 'correspondence', 'finance'] as $order => $key) {
                $layout['components'][$key]['section'] = 'executive';
                $layout['components'][$key]['order'] = $order;
                $layout['components'][$key]['size'] = $key === 'executive' ? 'full' : 'half';
            }
            $layout['components']['executive']['visible'] = true;
            $layout['components']['executive']['size'] = 'full';
            uasort($layout['components'], fn (array $a, array $b): int => $a['order'] <=> $b['order']);
        }

        $layout['components']['kpis']['visible'] = false;

        return $this->resolved[(int) $user->id] = $layout;
    }

    /**
     * Merge templates (level => templates) into the effective layout. Pure: no database access.
     *
     * @param  array<string, list<array<string, mixed>>>  $layers
     * @return array{components: array<string, array<string, mixed>>, shortcuts: list<string>, shortcuts_source: ?string, hidden_navigation: list<string>, hidden_source: ?string, landing: ?string, landing_source: ?string, applied: list<string>}
     */
    public function merge(array $layers): array
    {
        $registry = DashboardComponents::all();
        $components = [];
        foreach ($registry as $key => $component) {
            $components[$key] = ['visible' => $component['visible'], 'order' => $component['order'], 'size' => $component['size'], 'options' => [], 'source' => 'الإعداد الافتراضي',
                'section' => $component['section'] ?? 'base'];
        }
        $shortcuts = [];
        $hidden = [];
        $landing = null;
        $sources = ['shortcuts' => null, 'hidden' => null, 'landing' => null];
        $applied = [];

        foreach (array_keys(self::LAYERS) as $layer) {
            $templates = $layers[$layer] ?? [];
            if ($templates === []) {
                continue;
            }
            $label = implode('، ', array_column($templates, 'label'));
            array_push($applied, ...array_column($templates, 'label'));

            $mentioned = [];
            foreach ($templates as $template) {
                foreach ((array) ($template['configuration']['components'] ?? []) as $key => $settings) {
                    if (isset($registry[$key]) && is_array($settings)) {
                        $mentioned[$key][] = $settings;
                    }
                }
            }
            foreach ($mentioned as $key => $list) {
                $votes = array_values(array_filter(array_column($list, 'visible'), fn ($vote): bool => is_bool($vote)));
                if ($votes !== []) {
                    $components[$key]['visible'] = in_array(true, $votes, true);
                    $components[$key]['source'] = $label;
                }
                $orders = array_values(array_filter(array_column($list, 'order'), fn ($order): bool => is_int($order)));
                if ($orders !== []) {
                    $components[$key]['order'] = min($orders);
                }
                $sizes = array_values(array_filter(array_column($list, 'size'), fn ($size): bool => isset(DashboardComponents::SIZE_RANK[$size])));
                if ($sizes !== []) {
                    usort($sizes, fn (string $a, string $b): int => DashboardComponents::SIZE_RANK[$b] <=> DashboardComponents::SIZE_RANK[$a]);
                    $components[$key]['size'] = $sizes[0];
                }
                foreach ($list as $settings) {
                    if (! empty($settings['options']) && is_array($settings['options'])) {
                        $components[$key]['options'] = $settings['options'];
                        break;
                    }
                }
            }

            $lists = array_values(array_filter(array_map(fn (array $template) => $template['configuration']['shortcuts'] ?? null, $templates), 'is_array'));
            if ($lists !== []) {
                $shortcuts = array_values(array_unique(array_merge(...$lists)));
                $sources['shortcuts'] = $label;
            }
            $hiddenLists = array_values(array_filter(array_map(fn (array $template) => $template['configuration']['hidden_navigation'] ?? null, $templates), 'is_array'));
            if ($hiddenLists !== []) {
                $hidden = array_values(array_intersect(...$hiddenLists));
                $sources['hidden'] = $label;
            }
            foreach ($templates as $template) {
                if (! empty($template['configuration']['landing'])) {
                    $landing = (string) $template['configuration']['landing'];
                    $sources['landing'] = $template['label'];
                    break;
                }
            }
        }

        // Locked components (the greeting and the user's work obligations) stay visible whatever a template says.
        foreach ($registry as $key => $component) {
            if ($component['locked']) {
                $components[$key]['visible'] = true;
            }
        }
        uksort($components, fn (string $a, string $b): int => [$components[$a]['order'], $registry[$a]['order']] <=> [$components[$b]['order'], $registry[$b]['order']]);

        return ['components' => $components, 'shortcuts' => $shortcuts, 'shortcuts_source' => $sources['shortcuts'], 'hidden_navigation' => $hidden,
            'hidden_source' => $sources['hidden'], 'landing' => $landing, 'landing_source' => $sources['landing'], 'applied' => $applied];
    }

    /**
     * Why a component cannot appear for this user, from the user's own access; null when it can.
     */
    public function unavailableReason(User $user, string $key): ?string
    {
        return match ($key) {
            'readiness' => $user->isSystemAdmin() ? null : 'مخصص لمدير النظام.',
            'kpis' => 'مؤشرات الأداء متاحة من القائمة الجانبية فقط.',
            'executive' => app(Authority::class)->isExecutive($user) ? null : 'ليس المدير التنفيذي المعيّن.',
            'team' => app(Authority::class)->isHead($user) ? null : 'ليس رئيسًا معيّنًا لقسم فعال بدور رئيس قسم فيه.',
            'finance' => app(FinanceApprovalQueue::class)->isApprover($user) || app(FinanceGuard::class)->allows($user, 'finance.view')
                ? null : 'لا يملك صلاحية في وحدة الحسابات (دور مالي مع تخصيص من «صلاحيات الحسابات»).',
            // The leave request is always listed (with the reason when it cannot be made), so every staff member sees the block.
            'start_options' => $user->is_active && ($user->department_id || $this->rules->allows($user, 'leave_requester'))
                ? null : 'حساب غير مرتبط بقسم؛ لا يقدّم طلبات شخصية.',
            'shortcuts' => $this->shortcuts($user)['allowed'] !== [] ? null : 'لا يوجد في القالب رابط يملك فتحه.',
            'hr_services' => HrServicesDashboard::canView($user) ? null : 'لرئيس الموارد البشرية المعيّن ومدير النظام والمدير التنفيذي.',
            'rentals' => RentalsDashboard::canView($user) ? null : 'لرئيس قسم الأصول والمباني المعيّن ومسؤول ملف الإيجارات والمدير التنفيذي.',
            default => null,
        };
    }

    /**
     * The template's shortcuts the user may open, and the ones dropped with the reason.
     *
     * @return array{allowed: list<array{code: string, label: string, url: string, icon: ?string}>, denied: list<array{code: string, label: string, reason: string}>}
     */
    public function shortcuts(User $user): array
    {
        $codes = $this->resolve($user)['shortcuts'];
        if ($codes === []) {
            return ['allowed' => [], 'denied' => []];
        }
        $items = DB::table('navigation_items')->whereIn('code', $codes)->get(['code', 'label_ar', 'icon', 'route_name', 'access_rule', 'is_active'])->keyBy('code');
        $allowed = [];
        $denied = [];
        foreach ($codes as $code) {
            $item = $items[$code] ?? null;
            if (in_array($code, $this->resolve($user)['hidden_navigation'], true)) {
                $denied[] = ['code' => $code, 'label' => $item->label_ar ?? $code, 'reason' => 'مخفي في لوحة المستخدم.'];

                continue;
            }
            if (! $item || ! $item->is_active || ! $item->route_name || str_starts_with($item->route_name, '@') || ! Route::has($item->route_name)) {
                $denied[] = ['code' => $code, 'label' => $item->label_ar ?? $code, 'reason' => 'الرابط غير موجود أو معطّل في القائمة.'];

                continue;
            }
            if (! $this->rules->allows($user, (string) $item->access_rule)) {
                $denied[] = ['code' => $code, 'label' => $item->label_ar, 'reason' => $this->rules->explain($user, (string) $item->access_rule)];

                continue;
            }
            $allowed[] = ['code' => $code, 'label' => $item->label_ar, 'url' => route($item->route_name, [], false), 'icon' => $item->icon];
        }

        return ['allowed' => $allowed, 'denied' => $denied];
    }

    /**
     * Where the user lands after signing in: the template's page when they may open it, otherwise home.
     */
    public function landingUrl(User $user): ?string
    {
        $code = $this->resolve($user)['landing'];
        if ($code === null || $code === 'home') {
            return null;
        }
        if (in_array($code, $this->resolve($user)['hidden_navigation'], true)) {
            return null;
        }
        $item = DB::table('navigation_items')->where('code', $code)->where('is_active', true)->first(['route_name', 'access_rule']);
        if (! $item || ! $item->route_name || str_starts_with($item->route_name, '@') || ! Route::has($item->route_name)
            || ! $this->rules->allows($user, (string) $item->access_rule)) {
            return null;
        }

        return route($item->route_name, [], false);
    }

    public function label(string $type, string $key): string
    {
        return match ($type) {
            'organization' => self::LAYERS['organization'],
            'role' => 'دور: '.(DB::table('roles')->where('code', $key)->value('name') ?? $key),
            'department' => 'قسم: '.(DB::table('departments')->where('id', (int) $key)->value('name') ?? '#'.$key),
            'user' => 'مستخدم: '.(DB::table('users')->where('id', (int) $key)->value('name') ?? '#'.$key),
            default => $type,
        };
    }
}
