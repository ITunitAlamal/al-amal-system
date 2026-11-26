<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saves dashboard layout templates. Every save adds a version (nothing is overwritten), restoring copies an
 * older version into a new one, and each change is audited with its reason. Layout is never permission.
 */
final class DashboardLayouts
{
    private const ATTRIBUTES = ['scope_type' => 'نوع القالب', 'scope_key' => 'الجهة', 'reason' => 'سبب التغيير', 'configuration' => 'إعداد اللوحة',
        'configuration.shortcuts' => 'الاختصارات', 'configuration.hidden_navigation' => 'عناصر القائمة المخفية', 'configuration.landing' => 'صفحة البداية'];

    public function __construct(private readonly AuditService $audit, private readonly DashboardLayoutResolver $resolver) {}

    /**
     * @return array{scope_type: string, scope_key: string, configuration: array<string, mixed>, reason: string}
     */
    public function validate(array $input): array
    {
        $navigation = DB::table('navigation_items')->pluck('route_name', 'code');
        $data = Validator::make($input, [
            'scope_type' => ['required', Rule::in(array_keys(DashboardLayoutResolver::LAYERS))],
            'scope_key' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'configuration' => ['required', 'array'],
            'configuration.components' => ['present', 'array'],
            'configuration.shortcuts' => ['nullable', 'array', 'max:12'],
            'configuration.shortcuts.*' => ['string', 'distinct', Rule::in($navigation->filter(fn ($route) => $route && ! str_starts_with($route, '@'))->keys()->all())],
            'configuration.hidden_navigation' => ['nullable', 'array', 'max:40'],
            'configuration.hidden_navigation.*' => ['string', 'distinct', Rule::in($navigation->keys()->reject(fn ($code) => $code === 'dashboard')->all())],
            'configuration.landing' => ['nullable', 'string', Rule::in(['home', ...$navigation->filter(fn ($route) => $route && ! str_starts_with($route, '@'))->keys()->all()])],
            'configuration.include_subunits' => ['sometimes', 'boolean'],
        ], ['configuration.hidden_navigation.*.in' => 'لا يُخفى رابط الصفحة الرئيسية، ولا رابط غير موجود في القائمة.'], self::ATTRIBUTES)->validate();

        $key = match ($data['scope_type']) {
            'organization' => '',
            'role' => DB::table('roles')->where('code', (string) $data['scope_key'])->exists() ? (string) $data['scope_key'] : null,
            'department' => DB::table('departments')->where('id', (int) $data['scope_key'])->whereNull('deleted_at')->exists() ? (string) (int) $data['scope_key'] : null,
            'user' => DB::table('users')->where('id', (int) $data['scope_key'])->exists() ? (string) (int) $data['scope_key'] : null,
        };
        if ($key === null) {
            throw ValidationException::withMessages(['scope_key' => 'اختر الجهة التي يُطبق عليها القالب من القائمة.']);
        }

        $registry = DashboardComponents::all();
        $components = [];
        foreach ($data['configuration']['components'] as $component => $settings) {
            if (! isset($registry[$component]) || ! is_array($settings)) {
                throw ValidationException::withMessages(['configuration.components' => 'مكوّن غير مسجل في اللوحة.']);
            }
            $clean = [];
            if (array_key_exists('visible', $settings) && $settings['visible'] !== null) {
                if (! is_bool($settings['visible'])) {
                    throw ValidationException::withMessages(['configuration.components' => 'قيمة الإظهار غير صحيحة.']);
                }
                if ($registry[$component]['locked'] && $settings['visible'] === false) {
                    throw ValidationException::withMessages(['configuration.components' => '«'.$registry[$component]['label'].'» ثابت ولا يُخفى.']);
                }
                $clean['visible'] = $settings['visible'];
            }
            if (isset($settings['order']) && $settings['order'] !== '') {
                if (filter_var($settings['order'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000]]) === false) {
                    throw ValidationException::withMessages(['configuration.components' => 'الترتيب رقم بين 0 و1000.']);
                }
                $clean['order'] = (int) $settings['order'];
            }
            if (! empty($settings['size'])) {
                if (! isset(DashboardComponents::SIZES[$settings['size']])) {
                    throw ValidationException::withMessages(['configuration.components' => 'حجم غير معروف.']);
                }
                $clean['size'] = $settings['size'];
            }
            $options = [];
            foreach ($registry[$component]['options'] as $option => $rule) {
                $value = $settings['options'][$option] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $rule['min'], 'max_range' => $rule['max']]]) === false) {
                    throw ValidationException::withMessages(['configuration.components' => '«'.$rule['label'].'» بين '.$rule['min'].' و'.$rule['max'].'.']);
                }
                $options[$option] = (int) $value;
            }
            if ($options !== []) {
                $clean['options'] = $options;
            }
            if ($clean !== []) {
                $components[$component] = $clean;
            }
        }
        $configuration = ['components' => $components];
        foreach (['shortcuts', 'hidden_navigation'] as $list) {
            if (isset($data['configuration'][$list])) {
                $configuration[$list] = array_values($data['configuration'][$list]);
            }
        }
        if (! empty($data['configuration']['landing'])) {
            $configuration['landing'] = $data['configuration']['landing'];
        }
        if ($data['scope_type'] === 'department' && ! empty($data['configuration']['include_subunits'])) {
            $configuration['include_subunits'] = true;
        }

        return ['scope_type' => $data['scope_type'], 'scope_key' => $key, 'configuration' => $configuration, 'reason' => trim($data['reason'])];
    }

    public function save(User $actor, array $input, ?string $ipAddress): int
    {
        abort_unless($actor->isSystemAdmin(), 403);
        $data = $this->validate($input);

        return DB::transaction(function () use ($actor, $data, $ipAddress): int {
            $layout = DB::table('dashboard_layouts')->where('scope_type', $data['scope_type'])->where('scope_key', $data['scope_key'])->lockForUpdate()->first();
            $before = null;
            if ($layout) {
                $before = json_decode((string) DB::table('dashboard_layout_versions')->where('layout_id', $layout->id)->where('version', $layout->current_version)->value('configuration'), true);
                $version = (int) DB::table('dashboard_layout_versions')->where('layout_id', $layout->id)->max('version') + 1;
                $id = (int) $layout->id;
            } else {
                $version = 1;
                $id = (int) DB::table('dashboard_layouts')->insertGetId(['scope_type' => $data['scope_type'], 'scope_key' => $data['scope_key'], 'is_active' => true,
                    'current_version' => 1, 'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('dashboard_layout_versions')->insert(['layout_id' => $id, 'version' => $version, 'change_reason' => $data['reason'], 'created_by' => $actor->id,
                'created_at' => now(), 'configuration' => json_encode($data['configuration'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            DB::table('dashboard_layouts')->where('id', $id)->update(['current_version' => $version, 'updated_by' => $actor->id, 'updated_at' => now()]);
            $this->audit->recordRow('dashboard_layout.saved', 'dashboard_layouts', $id, $before ? ['version' => $version - 1, 'configuration' => $before] : null,
                ['scope' => $this->resolver->label($data['scope_type'], $data['scope_key']), 'version' => $version, 'configuration' => $data['configuration'], 'reason' => $data['reason']],
                (int) $actor->id, $ipAddress);

            return $id;
        });
    }

    public function restore(User $actor, int $layoutId, int $version, string $reason, ?string $ipAddress): void
    {
        abort_unless($actor->isSystemAdmin(), 403);
        $reason = $this->reason($reason);
        DB::transaction(function () use ($actor, $layoutId, $version, $reason, $ipAddress): void {
            $layout = DB::table('dashboard_layouts')->where('id', $layoutId)->lockForUpdate()->first();
            abort_unless($layout, 404);
            $source = DB::table('dashboard_layout_versions')->where('layout_id', $layoutId)->where('version', $version)->first();
            abort_unless($source, 404);
            $next = (int) DB::table('dashboard_layout_versions')->where('layout_id', $layoutId)->max('version') + 1;
            DB::table('dashboard_layout_versions')->insert(['layout_id' => $layoutId, 'version' => $next, 'configuration' => $source->configuration,
                'change_reason' => mb_substr('استرجاع الإصدار '.$version.': '.$reason, 0, 500), 'created_by' => $actor->id, 'created_at' => now()]);
            DB::table('dashboard_layouts')->where('id', $layoutId)->update(['current_version' => $next, 'updated_by' => $actor->id, 'updated_at' => now()]);
            $this->audit->recordRow('dashboard_layout.restored', 'dashboard_layouts', $layoutId, ['version' => (int) $layout->current_version],
                ['version' => $next, 'restored_from' => $version, 'reason' => $reason], (int) $actor->id, $ipAddress);
        });
    }

    public function setActive(User $actor, int $layoutId, bool $active, string $reason, ?string $ipAddress): void
    {
        abort_unless($actor->isSystemAdmin(), 403);
        $reason = $this->reason($reason);
        DB::transaction(function () use ($actor, $layoutId, $active, $reason, $ipAddress): void {
            $layout = DB::table('dashboard_layouts')->where('id', $layoutId)->lockForUpdate()->first();
            abort_unless($layout, 404);
            DB::table('dashboard_layouts')->where('id', $layoutId)->update(['is_active' => $active, 'updated_by' => $actor->id, 'updated_at' => now()]);
            $this->audit->recordRow($active ? 'dashboard_layout.activated' : 'dashboard_layout.deactivated', 'dashboard_layouts', $layoutId,
                ['is_active' => (bool) $layout->is_active], ['is_active' => $active, 'reason' => $reason], (int) $actor->id, $ipAddress);
        });
    }

    private function reason(string $reason): string
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'اكتب سببًا بين 5 و500 حرف.']);
        }

        return $reason;
    }
}
