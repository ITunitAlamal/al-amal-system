<?php

namespace App\Services\Configuration;

use App\Models\User;
use App\Services\Dashboard\DashboardLayoutResolver;
use App\Services\Workflow\WorkflowCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Builds the sidebar for one user from the navigation_items table.
 */
final class NavigationBuilder
{
    /**
     * A menu item whose route is this placeholder expands into one link per
     * workflow that employees may start, so new request types appear automatically.
     */
    public const STARTABLE_WORKFLOWS = '@startable_workflows';

    /** Icons the sidebar knows how to draw. */
    public const ICONS = [
        'layout', 'folder', 'users', 'building', 'coins', 'clipboard-list', 'wrench', 'save',
        'calendar', 'handshake', 'shopping-cart', 'inbox', 'settings', 'key', 'git-branch', 'list', 'chart',
        'mail', 'wallet', 'heart', 'file-text',
    ];

    public function __construct(
        private readonly NavigationAccessRules $rules,
        private readonly WorkflowCatalog $workflows,
    ) {}

    /**
     * @return list<array{code: string, label: string, url: ?string, icon: string, children: list<array<string, mixed>>}>
     */
    public function forUser(?User $user): array
    {
        if (! $user?->is_active) {
            return [];
        }

        return $user->withSystemAdminSnapshot(fn (): array => $this->buildMenu($user));
    }

    /** @return list<array<string, mixed>> */
    private function buildMenu(User $user): array
    {

        $items = DB::table('navigation_items')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'code', 'parent_id', 'label_ar', 'icon', 'route_name', 'route_params', 'access_rule', 'audience_roles']);

        $roles = $this->roleCodes($user);
        $allowedRules = [];
        $visible = $items->filter(function (object $item) use ($user, $roles, &$allowedRules): bool {
            return self::inAudience($item->audience_roles, $roles)
                && ($allowedRules[$item->access_rule] ??= $this->rules->allows($user, $item->access_rule));
        });
        $children = $visible->whereNotNull('parent_id')->groupBy('parent_id');

        $menu = [];
        foreach ($visible->whereNull('parent_id') as $item) {
            $childLinks = $this->withoutEmptyHeadings($this->links($children->get($item->id, collect()), $user));

            if ($item->route_name === null) {
                // A group is only worth showing when something inside it is visible.
                if ($childLinks !== []) {
                    $menu[] = $this->node($item, null, $childLinks);
                }

                continue;
            }

            foreach ($this->links(collect([$item]), $user) as $link) {
                $menu[] = [...$link, 'children' => $childLinks];
            }
        }

        return $this->withoutHidden($menu, app(DashboardLayoutResolver::class)->resolve($user)['hidden_navigation']);
    }

    /**
     * Role codes the user holds today, plus «department_head» for a head appointed in the structure.
     *
     * @return list<string>
     */
    public function roleCodes(User $user): array
    {
        $codes = DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->where('a.user_id', $user->id)
            ->where(fn ($query) => $query->whereNull('a.starts_on')->orWhere('a.starts_on', '<=', today()->toDateString()))
            ->where(fn ($query) => $query->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', today()->toDateString()))
            ->pluck('r.code')->all();
        if (DB::table('departments')->where('head_user_id', $user->id)->where('is_active', true)->whereNull('deleted_at')->exists()) {
            $codes[] = 'department_head';
        }

        return array_values(array_unique($codes));
    }

    /**
     * An entry restricted to some roles shows only to holders of one of them; an empty restriction means everyone
     * the visibility rule already allows.
     *
     * @param  list<string>  $roles
     */
    public static function inAudience(mixed $audience, array $roles): bool
    {
        $wanted = is_string($audience) ? (json_decode($audience, true) ?: []) : (array) ($audience ?? []);

        return $wanted === [] || array_intersect($wanted, $roles) !== [];
    }

    /**
     * For the administrator's preview: whether each entry's page and visibility rule allow this user (and why not),
     * plus the user's roles so the editor can apply unsaved role restrictions itself.
     *
     * @return array{entries: array<string, array{allowed: bool, reason: string}>, roles: list<string>, workflows: int}
     */
    public function explainFor(User $user): array
    {
        $hidden = app(DashboardLayoutResolver::class)->resolve($user)['hidden_navigation'];
        $entries = [];
        foreach (DB::table('navigation_items')->get(['code', 'route_name', 'access_rule']) as $item) {
            $reason = match (true) {
                $item->route_name !== null && $item->route_name !== self::STARTABLE_WORKFLOWS && ! Route::has($item->route_name) => 'الصفحة غير موجودة في النظام.',
                ! $this->rules->allows($user, $item->access_rule) => $this->rules->explain($user, $item->access_rule),
                in_array($item->code, $hidden, true) => 'مخفي بقالب في «تخصيص اللوحات».',
                default => '',
            };
            $entries[$item->code] = ['allowed' => $reason === '', 'reason' => $reason];
        }

        return ['entries' => $entries, 'roles' => $this->roleCodes($user), 'workflows' => count($this->workflows->startableFor($user))];
    }

    /**
     * Drops the items a dashboard template hides for this user. Templates only remove links; they never add one
     * the access rules above did not already allow.
     *
     * @param  list<array<string, mixed>>  $menu
     * @param  list<string>  $hidden
     * @return list<array<string, mixed>>
     */
    private function withoutHidden(array $menu, array $hidden): array
    {
        if ($hidden === []) {
            return $menu;
        }
        $kept = [];
        foreach ($menu as $item) {
            if (in_array($item['code'], $hidden, true)) {
                continue;
            }
            $item['children'] = $this->withoutEmptyHeadings(array_values(array_filter($item['children'], fn (array $child): bool => ! in_array($child['code'], $hidden, true))));
            if ($item['url'] === null && $item['children'] === []) {
                continue;
            }
            $kept[] = $item;
        }

        return $kept;
    }

    /**
     * @param  Collection<int, object>  $items
     * @return list<array{code: string, label: string, url: ?string, icon: string, children: list<array<string, mixed>>}>
     */
    private function links(Collection $items, User $user): array
    {
        $links = [];
        foreach ($items as $item) {
            if ($item->route_name === self::STARTABLE_WORKFLOWS) {
                foreach ($this->workflows->startableFor($user) as $workflow) {
                    $links[] = [
                        'code' => 'workflow:'.$workflow->code,
                        'label' => $workflow->name,
                        'url' => route('requests.create', ['code' => $workflow->code], false),
                        'icon' => $item->icon,
                        'children' => [],
                    ];
                }

                continue;
            }

            if ($item->route_name !== null && ! Route::has($item->route_name)) {
                continue;
            }
            if ($item->route_name === null && $item->parent_id !== null) {
                // A link-less entry inside a group is a section heading.
                $links[] = $this->node($item, null, []) + ['heading' => true];

                continue;
            }

            $params = $item->route_params ? (json_decode((string) $item->route_params, true) ?: []) : [];
            $links[] = $this->node($item, $item->route_name ? route($item->route_name, $params, false) : null, []);
        }

        return $links;
    }

    /**
     * Section headings are kept only when at least one link follows them before the next heading.
     *
     * @param  list<array<string, mixed>>  $links
     * @return list<array<string, mixed>>
     */
    private function withoutEmptyHeadings(array $links): array
    {
        $kept = [];
        foreach ($links as $index => $link) {
            if (! ($link['heading'] ?? false)) {
                $kept[] = $link;

                continue;
            }
            $next = $links[$index + 1] ?? null;
            if ($next !== null && ! ($next['heading'] ?? false)) {
                $kept[] = $link;
            }
        }

        return $kept;
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array{code: string, label: string, url: ?string, icon: string, children: list<array<string, mixed>>}
     */
    private function node(object $item, ?string $url, array $children): array
    {
        return [
            'code' => $item->code,
            'label' => $item->label_ar,
            'url' => $url,
            'icon' => in_array($item->icon, self::ICONS, true) ? $item->icon : 'folder',
            'children' => $children,
        ];
    }
}
