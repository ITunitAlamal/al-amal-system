<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Configuration\GazaGeography;
use App\Services\Orphans\RegistryQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Central catalog controller. All mutations require a system administrator.
 * Specialized catalogs (currencies, buildings, etc.) are deliberately not managed here.
 */
class MasterDataController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSystemAdmin(), 403, 'إدارة ثوابت النظام متاحة لمدير النظام فقط.');
    }

    private function audit(Request $request, string $action, string $table, int $id, ?array $before, ?array $after): void
    {
        DB::table('audit_logs')->insert([
            'actor_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => $table,
            'subject_id' => $id,
            'metadata' => json_encode(['before' => $before, 'after' => $after], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function index(Request $request): Response
    {
        $this->authorizeAdmin($request);

        $groups = DB::table('master_data_groups')
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'code', 'name_ar', 'name_en', 'description', 'is_active', 'sort_order']);

        $groupId = $request->integer('group_id') ?: null;
        if ($groupId !== null && ! $groups->contains('id', $groupId)) {
            abort(404, 'مجموعة الثوابت غير موجودة.');
        }

        $types = $groupId === null
            ? collect()
            : DB::table('master_data_types')->where('group_id', $groupId)
                ->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'group_id', 'code', 'name_ar', 'name_en', 'description', 'is_active', 'sort_order']);

        $typeId = $request->integer('type_id') ?: null;
        if ($typeId !== null && ! $types->contains('id', $typeId)) {
            abort(404, 'نوع الثابت غير تابع للمجموعة المحددة.');
        }

        $search = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'parent_id' => ['nullable', 'integer'], 'active' => ['nullable', 'in:0,1']]);
        $selectedType = $types->firstWhere('id', $typeId);
        $geographic = $selectedType && in_array($selectedType->code, GazaGeography::TYPES, true);
        $values = $typeId === null
            ? null
            : DB::table('master_data_values')->where('type_id', $typeId)
                ->when($search['q'] ?? null, fn ($query, $value) => $query->where(fn ($q) => $q->where('name_ar', 'like', '%'.$value.'%')->orWhere('code', 'like', '%'.$value.'%')))
                ->when(isset($search['parent_id']), fn ($query) => $query->where('parent_id', $search['parent_id']))
                ->when(isset($search['active']), fn ($query) => $query->where('is_active', (bool) $search['active']))
                ->orderBy('sort_order')->orderBy('id')
                ->paginate(25, ['id', 'type_id', 'parent_id', 'code', 'name_ar', 'name_en', 'description', 'is_active', 'sort_order', 'effective_from', 'effective_to', 'metadata'])
                ->withQueryString();

        $parentType = $geographic ? (GazaGeography::TYPES[array_search($selectedType->code, GazaGeography::TYPES, true) - 1] ?? null) : null;
        $nodes = $geographic ? app(GazaGeography::class)->nodes() : collect();
        $parents = $parentType ? $nodes->where('type_code', $parentType)->map(fn ($node) => ['id' => $node->id, 'name_ar' => $node->name_ar, 'parent_name' => $nodes->get($node->parent_id)?->name_ar, 'is_active' => (bool) $node->is_active])->values() : [];

        return Inertia::render('Admin/MasterData/Index', [
            'groups' => $groups,
            'types' => $types,
            'values' => $values,
            'selectedGroupId' => $groupId,
            'selectedTypeId' => $typeId,
            'geographic' => (bool) $geographic, 'geographicParents' => $parents, 'filters' => $search,
            'editingValue' => $request->input('view') === 'edit-value' && $typeId ? DB::table('master_data_values')->where('id', $request->integer('id'))->where('type_id', $typeId)->first() : null,
        ]);
    }

    public function geography(Request $request, GazaGeography $geography): JsonResponse
    {
        abort_unless($request->user()?->is_active, 403);
        $input = $request->validate(['parent_id' => ['nullable', 'integer', 'min:1']]);

        return response()->json(['data' => $geography->children(isset($input['parent_id']) ? (int) $input['parent_id'] : null)]);
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('master_data_groups', 'code')],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $id = DB::table('master_data_groups')->insertGetId([
                ...$data,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => true,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, 'master_data.group.created', 'master_data_groups', $id, null, $data);
        });

        return back()->with('success', 'تمت إضافة مجموعة الثوابت.');
    }

    public function updateGroup(Request $request, int $group): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $group, $data): void {
            $existing = DB::table('master_data_groups')->where('id', $group)->lockForUpdate()->first();
            abort_unless($existing, 404);
            DB::table('master_data_groups')->where('id', $group)->update([
                ...$data, 'updated_by' => $request->user()->id, 'updated_at' => now(),
            ]);
            $this->audit($request, 'master_data.group.updated', 'master_data_groups', $group, (array) $existing, $data);
        });

        return back()->with('success', 'تم تحديث مجموعة الثوابت.');
    }

    public function storeType(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'group_id' => ['required', 'integer', Rule::exists('master_data_groups', 'id')],
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('master_data_types', 'code')],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($request, $data): void {
            $group = DB::table('master_data_groups')->where('id', $data['group_id'])->lockForUpdate()->first();
            if (! $group || ! $group->is_active) {
                throw ValidationException::withMessages(['group_id' => 'اختر مجموعة نشطة.']);
            }
            $id = DB::table('master_data_types')->insertGetId([
                ...$data,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => true,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, 'master_data.type.created', 'master_data_types', $id, null, $data);
        });

        return back()->with('success', 'تمت إضافة نوع الثابت.');
    }

    public function updateType(Request $request, int $type): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $type, $data): void {
            $existing = DB::table('master_data_types')->where('id', $type)->lockForUpdate()->first();
            abort_unless($existing, 404);
            DB::table('master_data_types')->where('id', $type)->update([
                ...$data, 'updated_by' => $request->user()->id, 'updated_at' => now(),
            ]);
            $this->audit($request, 'master_data.type.updated', 'master_data_types', $type, (array) $existing, $data);
        });

        return back()->with('success', 'تم تحديث نوع الثابت.');
    }

    public function storeValue(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'type_id' => ['required', 'integer', Rule::exists('master_data_types', 'id')],
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('master_data_values', 'code')->where('type_id', $request->integer('type_id'))],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('master_data_values', 'id')],
            'location_type' => ['nullable', 'string', 'max:100'],
            'verification_status' => ['nullable', 'in:unverified,verified'],
            'verification_note' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'aliases' => ['nullable', 'string', 'max:2000'],
            ...$this->effectiveDateRules(),
        ]);
        $aliases = self::aliases($data['aliases'] ?? null);
        unset($data['aliases']);
        if ($aliases !== []) {
            $data['metadata'] = json_encode(['aliases' => $aliases], JSON_UNESCAPED_UNICODE);
        }

        DB::transaction(function () use ($request, $data): void {
            $type = DB::table('master_data_types')->where('id', $data['type_id'])->lockForUpdate()->first();
            $group = $type ? DB::table('master_data_groups')->where('id', $type->group_id)->first() : null;
            if (! $type || ! $type->is_active || ! $group || ! $group->is_active) {
                throw ValidationException::withMessages(['type_id' => 'اختر نوع ثابت نشطًا ضمن مجموعة نشطة.']);
            }
            if (in_array($type->code, GazaGeography::TYPES, true)) {
                $data = [...$data, ...app(GazaGeography::class)->validateNode($type->code, $data)];
            } else {
                $this->checkParent($data['parent_id'] ?? null, (int) $type->id);
            }
            unset($data['location_type'], $data['verification_status'], $data['verification_note']);
            $this->checkSystemReadValue($type, $data['code'], true, null);
            $id = DB::table('master_data_values')->insertGetId([
                ...$data,
                'sort_order' => $data['sort_order'] ?? 0,
                'is_active' => true,
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit($request, 'master_data.value.created', 'master_data_values', $id, null, $data);
        });

        RegistryQueries::forgetOptions();

        return back()->with('success', 'تمت إضافة قيمة الثابت.');
    }

    public function updateValue(Request $request, int $value): RedirectResponse
    {
        $this->authorizeAdmin($request);
        $existing = DB::table('master_data_values')->where('id', $value)->first();
        abort_unless($existing, 404);
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order' => ['required', 'integer', 'min:0'],
            'is_active' => ['required', 'boolean'],
            ...$this->effectiveDateRules(),
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('master_data_values', 'id')],
            'location_type' => ['nullable', 'string', 'max:100'],
            'verification_status' => ['nullable', 'in:unverified,verified'],
            'verification_note' => ['nullable', 'string', 'max:1000'],
            'aliases' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $value, $data): void {
            $before = DB::table('master_data_values')->where('id', $value)->lockForUpdate()->first();
            abort_unless($before, 404);
            if (array_key_exists('aliases', $data)) {
                // Aliases live in metadata next to other keys (geography, source), which stay untouched.
                $meta = json_decode((string) ($before->metadata ?? '{}'), true) ?: [];
                $meta['aliases'] = array_values(array_diff(self::aliases($data['aliases']), [trim($data['name_ar'])]));
                if ($meta['aliases'] === []) {
                    unset($meta['aliases']);
                }
                $data['metadata'] = $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE);
                unset($data['aliases']);
            }
            $type = DB::table('master_data_types')->where('id', $before->type_id)->lockForUpdate()->first();
            if (isset(json_decode($before->metadata ?? '{}', true)['geography'])) {
                $data = [...$data, ...app(GazaGeography::class)->validateNode($type->code, $data, $before)];
            } else {
                unset($data['parent_id']);
            }
            unset($data['location_type'], $data['verification_status'], $data['verification_note']);
            $this->checkSystemReadValue($type, $before->code, (bool) $data['is_active'], (int) $before->id);
            DB::table('master_data_values')->where('id', $value)->update([
                ...$data, 'updated_by' => $request->user()->id, 'updated_at' => now(),
            ]);
            $this->audit($request, 'master_data.value.updated', 'master_data_values', $value, (array) $before, $data);
        });

        RegistryQueries::forgetOptions();

        return back()->with('success', 'تم تحديث قيمة الثابت.');
    }

    /** One alias per line (or comma), trimmed and unique. @return list<string> */
    private static function aliases(?string $text): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($s) => mb_substr(trim($s), 0, 150), preg_split('/[\r\n،,]+/u', (string) $text) ?: []), fn ($s) => $s !== '')));
    }

    /**
     * @return array<string, list<string>>
     */
    private function effectiveDateRules(): array
    {
        return [
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after_or_equal:effective_from'],
        ];
    }

    /**
     * Constants the system reads as numbers. The attendance grace period is read from the value code
     * (min_10 = 10 minutes) and exactly one value may be active; anything else stops attendance processing.
     */
    private function checkSystemReadValue(?object $type, string $code, bool $active, ?int $valueId): void
    {
        if ($type?->code !== 'attendance_grace_minutes') {
            return;
        }
        if (! preg_match('/^min_(0|[1-9][0-9]{0,2})$/D', $code) || (int) substr($code, 4) > 240) {
            throw ValidationException::withMessages([
                'code' => 'رمز فترة السماح يُكتب بالصيغة min_ثم عدد الدقائق، مثل min_10 (من 0 إلى 240 دقيقة).',
            ]);
        }
        $otherActive = DB::table('master_data_values')->where('type_id', $type->id)->where('is_active', true)
            ->when($valueId, fn ($query) => $query->where('id', '<>', $valueId))->exists();
        if ($active && $otherActive) {
            throw ValidationException::withMessages([
                $valueId ? 'is_active' : 'code' => 'فترة السماح قيمة واحدة نشطة فقط. عطّل القيمة الحالية أولًا ثم فعّل الجديدة.',
            ]);
        }
    }

    private function checkParent(?int $parentId, int $typeId): void
    {
        if ($parentId === null) {
            return;
        }
        $parent = DB::table('master_data_values')->where('id', $parentId)->first();
        if (! $parent || (int) $parent->type_id !== $typeId || ! $parent->is_active) {
            throw ValidationException::withMessages([
                'parent_id' => 'القيمة الأب يجب أن تكون نشطة ومن نوع الثابت نفسه.',
            ]);
        }
    }
}
