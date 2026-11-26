<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Models\CurrencySetting;
use App\Models\FinancialAuthorityLimit;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinancialSettingsController extends Controller
{
    private const AUTHORITIES = [
        'executive_director',
        'treasurer',
        'board_chairman',
    ];

    private const ACTIONS = [
        'approve',
        'disburse',
    ];

    private function authorizeAdmin(Request $request): void
    {
        abort_unless(
            $request->user()?->isSystemAdmin(),
            403,
            'هذه الإعدادات متاحة لمدير النظام فقط.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'currencies' => Currency::query()
                ->orderBy('code')
                ->get(),

            'base_currency' => CurrencySetting::query()
                ->whereKey(1)
                ->value('base_currency_code'),

            'authorities' => self::AUTHORITIES,
            'actions' => self::ACTIONS,

            'financial_limits' => FinancialAuthorityLimit::query()
                ->orderBy('authority_code')
                ->orderBy('action_code')
                ->orderBy('currency')
                ->get(),

            'financial_approvals_enabled' => false,
        ]);
    }

    public function updateBaseCurrency(
        Request $request,
        AuditService $audit
    ): JsonResponse {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'base_currency_code' => [
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')
                    ->where('is_active', true),
            ],
        ]);

        $result = DB::transaction(function () use (
            $request,
            $audit,
            $data
        ) {
            $settings = CurrencySetting::query()
                ->whereKey(1)
                ->lockForUpdate()
                ->firstOrFail();

            $before = [
                'base_currency_code' => $settings->base_currency_code,
            ];

            $settings->base_currency_code =
                $data['base_currency_code'];

            $settings->updated_by = $request->user()->id;

            if ($settings->isDirty()) {
                $settings->save();

                $audit->record(
                    $request,
                    'financial_settings.base_currency.updated',
                    $settings,
                    $before,
                    [
                        'base_currency_code' => $settings->base_currency_code,
                    ]
                );
            }

            return $settings->base_currency_code;
        });

        return response()->json([
            'message' => 'تم حفظ العملة الأساسية بنجاح.',
            'base_currency_code' => $result,
            'notice' => 'لم يتم تحويل المبالغ أو السقوف المالية السابقة.',
        ]);
    }

    public function saveLimit(
        Request $request,
        AuditService $audit
    ): JsonResponse {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'authority_code' => [
                'required',
                Rule::in(self::AUTHORITIES),
            ],
            'action_code' => [
                'required',
                Rule::in(self::ACTIONS),
            ],
            'currency' => [
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')
                    ->where('is_active', true),
            ],
            'limit_amount' => [
                'nullable',
                'string',
                'max:30',
            ],
            'is_active' => [
                'required',
                'boolean',
            ],
            'decision_reference' => [
                'nullable',
                'string',
                'max:2000',
            ],
            'change_reason' => [
                'required',
                'string',
                'min:5',
                'max:500',
            ],
        ]);

        $active = (bool) $data['is_active'];

        $decisionReference = trim(
            $data['decision_reference'] ?? ''
        );

        $amount = trim(
            $data['limit_amount'] ?? ''
        );

        if ($active && $decisionReference === '') {
            throw ValidationException::withMessages([
                'decision_reference' => 'مرجع قرار التفويض إلزامي لتفعيل السقف.',
            ]);
        }

        if ($active && $amount === '') {
            throw ValidationException::withMessages([
                'limit_amount' => 'يجب تحديد السقف المالي قبل تفعيله.',
            ]);
        }

        $currency = Currency::query()
            ->whereKey($data['currency'])
            ->where('is_active', true)
            ->firstOrFail();

        $minorUnits = $amount === ''
            ? null
            : $this->parseMinorUnits(
                $amount,
                $currency->decimal_places
            );

        if ($active && ($minorUnits === null || $minorUnits <= 0)) {
            throw ValidationException::withMessages([
                'limit_amount' => 'يجب أن يكون السقف المفعّل أكبر من صفر.',
            ]);
        }

        $result = DB::transaction(function () use (
            $request,
            $audit,
            $data,
            $minorUnits,
            $decisionReference,
            $active
        ) {
            $query = FinancialAuthorityLimit::query()
                ->where('authority_code', $data['authority_code'])
                ->where('action_code', $data['action_code'])
                ->where('currency', $data['currency']);

            $limit = $query->lockForUpdate()->first();

            $before = $limit
                ? $limit->only([
                    'authority_code',
                    'action_code',
                    'currency',
                    'limit_minor_units',
                    'is_unlimited',
                    'is_active',
                    'decision_reference',
                ])
                : null;

            if (! $limit) {
                $limit = new FinancialAuthorityLimit;
                $limit->authority_code = $data['authority_code'];
                $limit->action_code = $data['action_code'];
                $limit->currency = $data['currency'];
            }

            $limit->limit_minor_units = $minorUnits;
            $limit->is_unlimited = false;
            $limit->is_active = $active;
            $limit->decision_reference =
                $decisionReference !== ''
                    ? $decisionReference
                    : null;
            $limit->updated_by = $request->user()->id;

            $limit->save();

            $after = $limit->only([
                'authority_code',
                'action_code',
                'currency',
                'limit_minor_units',
                'is_unlimited',
                'is_active',
                'decision_reference',
            ]);

            if ($before !== $after) {
                DB::table(
                    'financial_authority_limit_histories'
                )->insert([
                    'financial_authority_limit_id' => $limit->id,
                    'changed_by' => $request->user()->id,
                    'before_values' => $before === null
                        ? null
                        : json_encode(
                            $before,
                            JSON_UNESCAPED_UNICODE
                            | JSON_THROW_ON_ERROR
                        ),
                    'after_values' => json_encode(
                        $after,
                        JSON_UNESCAPED_UNICODE
                        | JSON_THROW_ON_ERROR
                    ),
                    'change_reason' => $data['change_reason'],
                    'changed_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $audit->record(
                    $request,
                    'financial_settings.limit.updated',
                    $limit,
                    $before,
                    $after
                );
            }

            return $limit->fresh();
        });

        return response()->json([
            'message' => 'تم حفظ إعدادات السقف المالي.',
            'financial_limit' => $result,
            'notice' => 'حفظ السقف لا يفعّل تنفيذ الموافقات أو الصرف.',
        ]);
    }

    private function parseMinorUnits(
        string $amount,
        int $decimalPlaces
    ): int {
        if ($decimalPlaces < 0 || $decimalPlaces > 3) {
            throw ValidationException::withMessages([
                'limit_amount' => 'دقة العملة غير مدعومة حاليًا.',
            ]);
        }

        if (! preg_match(
            '/^(0|[1-9][0-9]{0,11})(?:\.([0-9]+))?$/D',
            $amount,
            $matches
        )) {
            throw ValidationException::withMessages([
                'limit_amount' => 'صيغة المبلغ غير صحيحة. استخدم نقطة عشرية.',
            ]);
        }

        $fraction = $matches[2] ?? '';

        if (strlen($fraction) > $decimalPlaces) {
            throw ValidationException::withMessages([
                'limit_amount' => 'عدد المنازل العشرية أكبر من المسموح لهذه العملة.',
            ]);
        }

        $factor = 10 ** $decimalPlaces;

        return ((int) $matches[1] * $factor)
            + (int) str_pad(
                $fraction,
                $decimalPlaces,
                '0'
            );
    }
}
