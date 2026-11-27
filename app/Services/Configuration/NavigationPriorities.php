<?php

namespace App\Services\Configuration;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Presentation only: receives the already authorized menu and never creates a destination. */
final class NavigationPriorities
{
    /** @var array<string, list<string>> */
    public const ORDER = [
        'executive' => ['dashboard', 'finance_approvals', 'kpi_dashboard', 'my_department', 'my_tasks', 'official_correspondence', 'approval_delegations'],
        'administrator' => ['dashboard', 'employee_accounts', 'departments', 'master_data', 'audit_logs', 'navigation_admin', 'dashboard_layouts', 'my_tasks'],
        // The unified department (HR, general services and maintenance): the five daily destinations first, then its groups.
        'hr_head' => ['dashboard', 'my_tasks', 'my_requests_all', 'hr_dashboard', 'official_correspondence',
            'employee_profiles', 'attendance', 'attendance_compensatory', 'employee_accounts', 'attendance_operations',
            'services_requests', 'maintenance_requests', 'leaves_requests', 'leaves_balances', 'my_attendance', 'my_profile', 'my_todo'],
        'hr_staff' => ['dashboard', 'my_tasks', 'my_requests_all', 'hr_dashboard', 'official_correspondence', 'employee_profiles', 'attendance',
            'leaves_requests', 'leaves_balances', 'my_attendance', 'my_profile', 'my_todo'],
        'orphans' => ['orphans_home', 'orphans_registry', 'orphans_reviews', 'orphans_aid', 'orphans_campaigns', 'orphans_reports', 'dashboard', 'my_tasks'],
        'public_relations' => ['dashboard', 'my_tasks', 'official_correspondence', 'my_requests', 'my_profile'],
        'finance' => ['dashboard', 'finance_pending', 'finance_home', 'finance_approvals', 'finance_payments', 'finance_collections', 'finance_rentals', 'finance_reports', 'finance_payroll_runs', 'finance_staff', 'my_tasks'],
        'assets' => ['dashboard', 'rentals_file', 'rentals_buildings', 'my_tasks', 'official_correspondence', 'my_requests'],
        'maintenance' => ['dashboard', 'my_tasks', 'my_requests_all', 'hr_dashboard', 'official_correspondence',
            'leaves_requests', 'leaves_balances', 'my_attendance', 'my_profile', 'my_todo'],
        'employee' => ['dashboard', 'my_tasks', 'my_requests', 'attendance', 'leaves_requests', 'leaves_balances', 'my_profile', 'official_correspondence'],
    ];

    /**
     * Entries shown at the top level in one work context, wherever the administrator keeps them: «طلباتي» sits inside
     * «خدماتي» for everyone, and the people of the unified department open it directly. The entry is only moved; it is
     * already in the user's authorized menu, and every other context keeps the administrator's arrangement.
     *
     * @var array<string, list<string>>
     */
    public const LIFTED = [
        'hr_head' => ['my_requests_all', 'hr_dashboard'],
        'hr_staff' => ['my_requests_all', 'hr_dashboard'],
        'maintenance' => ['my_requests_all', 'hr_dashboard'],
    ];

    /** @var array<string, string> */
    private const DEPARTMENTS = ['HR' => 'hr_staff', 'ORPHANS' => 'orphans', 'PR' => 'public_relations', 'FIN' => 'finance', 'ASSETS' => 'assets', 'MAINT' => 'maintenance'];

    /** @param list<array<string, mixed>> $menu
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, array $menu): array
    {
        $contexts = $this->contexts($user, $menu);
        $context = $contexts[0];
        $request = request();
        if ($request->hasSession()) {
            $key = 'navigation.work_context.'.(int) $user->id;
            $remembered = $request->session()->get($key);
            if (is_string($remembered) && in_array($remembered, $contexts, true)) {
                $context = $remembered;
            }
            $request->session()->put($key, $context);
        }

        return $this->arrange($menu, $context);
    }

    /** @param list<array<string, mixed>> $menu
     * @return list<string>
     */
    public function contexts(User $user, array $menu): array
    {
        $assignments = DB::table('department_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')
            ->leftJoin('departments as d', 'd.id', '=', 'a.department_id')
            ->where('a.user_id', $user->id)
            ->where(fn ($query) => $query->whereNull('a.department_id')->orWhere(fn ($department) => $department->where('d.is_active', true)->whereNull('d.deleted_at')))
            ->where(fn ($query) => $query->whereNull('a.starts_on')->orWhere('a.starts_on', '<=', today()->toDateString()))
            ->where(fn ($query) => $query->whereNull('a.ends_on')->orWhere('a.ends_on', '>=', today()->toDateString()))
            ->orderBy('a.id')->get(['a.department_id', 'r.code']);
        if ($assignments->contains(fn (object $row): bool => $row->code === 'system_admin' && $row->department_id === null)) {
            return ['administrator'];
        }
        if ($assignments->contains('code', 'executive_director')) {
            return ['executive'];
        }

        $departments = DB::table('departments')->where('is_active', true)->whereNull('deleted_at')->get()->keyBy('id');
        $contexts = [];
        $ids = array_values(array_unique(array_filter([$user->department_id, ...$assignments->pluck('department_id')->all()])));
        foreach ($ids as $id) {
            $seen = [];
            while ($id && ! isset($seen[$id]) && ($department = $departments->get($id))) {
                $seen[$id] = true;
                $profile = self::DEPARTMENTS[$department->code ?? ''] ?? null;
                if ($profile !== null) {
                    $head = (int) ($department->head_user_id ?? 0) === (int) $user->id
                        && $assignments->contains(fn (object $row): bool => $row->code === 'department_head' && (int) $row->department_id === (int) $id);
                    $contexts[] = $profile === 'hr_staff' && $head ? 'hr_head' : $profile;
                    break;
                }
                $id = $department->parent_id;
            }
        }
        foreach ($assignments as $assignment) {
            if ($assignment->department_id !== null && ! $departments->has($assignment->department_id)) {
                continue;
            }
            if (in_array($assignment->code, ['finance_accountant', 'finance_head', 'finance_procurement', 'finance_warehouse', 'finance_payroll', 'finance_auditor', 'finance_cash_executor', 'finance_receipt_executor'], true)) {
                $contexts[] = 'finance';
            } elseif ($assignment->code === 'rentals_officer') {
                $contexts[] = 'assets';
            } elseif ($assignment->code === 'maintenance_technician') {
                $contexts[] = 'maintenance';
            }
        }

        return array_values(array_unique($contexts ?: ['employee']));
    }

    /** @param list<array<string, mixed>> $menu
     * @return list<array<string, mixed>>
     */
    public function arrange(array $menu, string $context): array
    {
        $priorities = array_flip(self::ORDER[$context] ?? self::ORDER['employee']);
        $menu = $this->lift($menu, self::LIFTED[$context] ?? []);
        foreach ($menu as &$item) {
            $children = $item['children'] ?? [];
            /** Keep custom section headings attached to their original links. */
            if (! array_filter($children, fn (array $child): bool => (bool) ($child['heading'] ?? false))) {
                $item['children'] = $this->sort($children, $priorities);
            }
        }
        unset($item);

        return $this->sort($menu, $priorities);
    }

    /**
     * Moves the given entries out of their group to the top level; a group left without links goes with them.
     * A group the administrator divided with section headings is left as arranged.
     *
     * @param  list<array<string, mixed>>  $menu
     * @param  list<string>  $codes
     * @return list<array<string, mixed>>
     */
    private function lift(array $menu, array $codes): array
    {
        if ($codes === []) {
            return $menu;
        }
        $kept = [];
        $lifted = [];
        foreach ($menu as $item) {
            $children = $item['children'] ?? [];
            $moving = array_values(array_filter($children, fn (array $child): bool => in_array($child['code'], $codes, true)));
            if ($moving === [] || array_filter($children, fn (array $child): bool => (bool) ($child['heading'] ?? false))) {
                $kept[] = $item;

                continue;
            }
            array_push($lifted, ...array_map(fn (array $child): array => [...$child, 'children' => []], $moving));
            $item['children'] = array_values(array_filter($children, fn (array $child): bool => ! in_array($child['code'], $codes, true)));
            if (($item['url'] ?? null) !== null || $item['children'] !== []) {
                $kept[] = $item;
            }
        }

        return [...$kept, ...$lifted];
    }

    /** @param list<array<string, mixed>> $items
     * @param  array<string, int>  $priorities
     * @return list<array<string, mixed>>
     */
    private function sort(array $items, array $priorities): array
    {
        $rank = static function (array $item) use ($priorities): int {
            $scores = [$priorities[$item['code']] ?? 1000];
            foreach ($item['children'] ?? [] as $child) {
                $scores[] = $priorities[$child['code']] ?? 1000;
            }

            return min($scores);
        };
        /** PHP's stable sort retains the administrator's order for equal/unlisted priorities. */
        usort($items, fn (array $left, array $right): int => $rank($left) <=> $rank($right));

        return $items;
    }
}
