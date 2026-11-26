<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Configuration\NavigationAccessRules;
use App\Services\Configuration\NavigationBuilder;
use App\Services\Configuration\NavigationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «القائمة الرئيسية» (SRS CFG04): the system administrator decides what the sidebar shows — name, icon, group,
 * order, hidden, and an optional restriction to some roles. Pages, their routes and visibility rules come from
 * NavigationRegistry and cannot be changed here, so the menu never offers a link its page would refuse.
 */
class NavigationController extends Controller
{
    public function __construct(
        private readonly NavigationAccessRules $rules,
        private readonly NavigationRegistry $registry,
        private readonly NavigationBuilder $builder,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);
        $this->registry->apply($request->user()->id, false, $request->ip());
        $items = $this->items();
        $ruleLabels = $this->rules->selectableRules();

        return Inertia::render('Admin/Navigation/Index', [
            'items' => $items->map(fn (object $item): array => [
                'id' => (int) $item->id, 'code' => $item->code, 'parent_id' => $item->parent_id === null ? null : (int) $item->parent_id,
                'label_ar' => $item->label_ar, 'icon' => $item->icon, 'is_active' => (bool) $item->is_active,
                'audience_roles' => json_decode((string) $item->audience_roles, true) ?: [],
                'is_group' => $item->route_name === null,
                'page' => $this->pageLabel($item),
                'rule' => $item->route_name === null ? null : ($ruleLabels[$item->access_rule] ?? $item->access_rule),
                'locked' => in_array($item->code, NavigationRegistry::LOCKED, true),
                'custom' => ! NavigationRegistry::isRegistered($item->code),
                'default_label' => NavigationRegistry::ITEMS[$item->code][0] ?? NavigationRegistry::GROUPS[$item->code][0] ?? null,
            ])->values(),
            'version' => $this->version($items),
            'roles' => DB::table('roles')->orderBy('name')->get(['code', 'name']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'icons' => NavigationBuilder::ICONS,
        ]);
    }

    /**
     * Saves the whole menu at once, in the order sent: groups and links with their names, icons, visibility and
     * role restrictions. Two levels only, and the home page and this page always stay visible to everyone.
     */
    public function saveLayout(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'version' => ['required', 'string', 'size:40'],
            'items' => ['required', 'array', 'max:500'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.parent_id' => ['nullable', 'integer'],
            'items.*.label_ar' => ['required', 'string', 'max:150'],
            'items.*.icon' => ['required', Rule::in(NavigationBuilder::ICONS)],
            'items.*.is_active' => ['required', 'boolean'],
            'items.*.audience_roles' => ['present', 'array', 'max:30'],
            'items.*.audience_roles.*' => ['string', Rule::exists('roles', 'code')],
        ], [], ['items.*.label_ar' => 'اسم العنصر', 'items.*.audience_roles.*' => 'الدور']);

        $changed = DB::transaction(function () use ($request, $data): int {
            $current = DB::table('navigation_items')->lockForUpdate()->orderBy('sort_order')->orderBy('id')->get();
            if (! hash_equals($this->version($current), $data['version'])) {
                throw ValidationException::withMessages(['version' => 'تغيّرت القائمة من مكان آخر بعد فتحك الصفحة؛ أعد تحميلها ثم كرر التعديل.']);
            }
            $rows = $current->keyBy('id');
            $incoming = collect($data['items'])->keyBy(fn (array $entry): int => (int) $entry['id']);
            if ($incoming->keys()->sort()->values()->all() !== $rows->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all()) {
                throw ValidationException::withMessages(['items' => 'أرسل القائمة كاملة؛ أعد تحميل الصفحة ثم احفظ.']);
            }
            $changes = [];
            foreach (array_values($data['items']) as $index => $entry) {
                $row = $rows->get((int) $entry['id']);
                $parentId = $entry['parent_id'] === null ? null : (int) $entry['parent_id'];
                $label = trim((string) $entry['label_ar']);
                if ($parentId !== null) {
                    $parent = $rows->get($parentId);
                    if ($row->route_name === null || ! $parent || $parent->route_name !== null || ($incoming->get($parentId)['parent_id'] ?? null) !== null) {
                        throw ValidationException::withMessages(['items' => "«{$label}»: القائمة من مستويين؛ تُنقل الروابط إلى مجموعة، ولا تُوضع مجموعة داخل مجموعة."]);
                    }
                }
                $audience = array_values(array_unique($entry['audience_roles']));
                if (in_array($row->code, NavigationRegistry::LOCKED, true) && (! $entry['is_active'] || $audience !== [])) {
                    throw ValidationException::withMessages(['items' => "«{$label}» يبقى ظاهرًا للجميع حتى لا يُغلق الطريق إلى الصفحة الرئيسية أو إلى هذه الصفحة."]);
                }
                $stored = json_decode((string) $row->audience_roles, true) ?: [];
                $after = ['parent_id' => $parentId, 'label_ar' => $label, 'icon' => $entry['icon'], 'is_active' => (bool) $entry['is_active'],
                    'sort_order' => ($index + 1) * 10, 'audience_roles' => $audience === [] ? null : json_encode($audience, JSON_UNESCAPED_UNICODE)];
                $before = ['parent_id' => $row->parent_id === null ? null : (int) $row->parent_id, 'label_ar' => $row->label_ar, 'icon' => $row->icon,
                    'is_active' => (bool) $row->is_active, 'sort_order' => (int) $row->sort_order,
                    'audience_roles' => $stored === [] ? null : json_encode(array_values($stored), JSON_UNESCAPED_UNICODE)];
                $diff = array_filter($after, fn ($value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH);
                if ($diff === []) {
                    continue;
                }
                DB::table('navigation_items')->where('id', $row->id)->update([...$diff, 'updated_by' => $request->user()->id, 'updated_at' => now()]);
                foreach ($diff as $field => $value) {
                    $changes[$row->code][$field] = [$before[$field], $value];
                }
            }
            if ($changes !== []) {
                $this->audit->recordRow('navigation.layout.saved', 'navigation_items', 0, null, ['changes' => $changes], $request->user()->id, $request->ip());
            }

            return count($changes);
        });

        return back()->with('success', $changed ? 'حُفظت القائمة. تظهر التغييرات لكل مستخدم عند فتحه أي صفحة.' : 'لا تغييرات للحفظ.');
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'label_ar' => ['required', 'string', 'max:150'],
            'icon' => ['required', Rule::in(NavigationBuilder::ICONS)],
        ], [], ['label_ar' => 'اسم المجموعة']);

        DB::transaction(function () use ($request, $data): void {
            $values = ['code' => 'group_'.Str::lower(Str::random(10)), 'parent_id' => null, 'label_ar' => trim($data['label_ar']), 'icon' => $data['icon'],
                'route_name' => null, 'route_params' => null, 'access_rule' => 'authenticated', 'audience_roles' => null, 'is_active' => true,
                'sort_order' => (int) DB::table('navigation_items')->whereNull('parent_id')->max('sort_order') + 10];
            $id = DB::table('navigation_items')->insertGetId([...$values, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->recordRow('navigation.group.created', 'navigation_items', $id, null, $values, $request->user()->id, $request->ip());
        });

        return back()->with('success', 'أُضيفت المجموعة في آخر القائمة؛ انقل إليها الروابط ثم احفظ.');
    }

    public function destroyGroup(Request $request, int $item): RedirectResponse
    {
        $this->authorizeAdmin($request);
        DB::transaction(function () use ($request, $item): void {
            $row = DB::table('navigation_items')->where('id', $item)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->route_name !== null || $row->parent_id !== null || NavigationRegistry::isRegistered($row->code)) {
                throw ValidationException::withMessages(['group' => 'تُحذف المجموعات التي أضفتها فقط؛ مجموعات النظام تُخفى ولا تُحذف.']);
            }
            if (DB::table('navigation_items')->where('parent_id', $item)->exists()) {
                throw ValidationException::withMessages(['group' => 'انقل روابط المجموعة إلى مكان آخر واحفظ، ثم احذفها.']);
            }
            DB::table('navigation_items')->where('id', $item)->delete();
            $this->audit->recordRow('navigation.group.deleted', 'navigation_items', $item, (array) $row, null, $request->user()->id, $request->ip());
        });

        return back()->with('success', 'حُذفت المجموعة.');
    }

    public function reset(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $this->registry->apply($request->user()->id, true, $request->ip());

        return back()->with('success', 'استُعيد التنظيم الافتراضي للقائمة. المجموعات التي أضفتها بقيت فارغة ويمكن حذفها.');
    }

    /** What the sidebar shows to one user, entry by entry, with the reason for each hidden one. */
    public function preview(Request $request, User $user): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json($this->builder->explainFor($user));
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSystemAdmin(), 403, 'إدارة القائمة الرئيسية متاحة لمدير النظام فقط.');
    }

    /** @return Collection<int, object> */
    private function items(): Collection
    {
        return DB::table('navigation_items')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'code', 'parent_id', 'label_ar', 'icon', 'route_name', 'route_params', 'access_rule', 'audience_roles', 'sort_order', 'is_active']);
    }

    /**
     * A fingerprint of everything this page can change, so a save made on a stale copy is refused.
     *
     * @param  Collection<int, object>  $items
     */
    private function version(Collection $items): string
    {
        return sha1(json_encode($items->sortBy('id')->map(fn (object $item): array => [(int) $item->id, $item->parent_id === null ? null : (int) $item->parent_id,
            (int) $item->sort_order, $item->label_ar, $item->icon, (bool) $item->is_active, json_decode((string) $item->audience_roles, true) ?: []])->values()->all(), JSON_UNESCAPED_UNICODE));
    }

    private function pageLabel(object $item): ?string
    {
        if ($item->route_name === null) {
            return null;
        }
        if ($item->route_name === NavigationBuilder::STARTABLE_WORKFLOWS) {
            return 'رابط لكل نوع طلب مفعّل (تلقائي)';
        }
        if (! Route::has($item->route_name)) {
            return 'صفحة غير موجودة';
        }

        return route($item->route_name, json_decode((string) $item->route_params, true) ?: [], false);
    }
}
