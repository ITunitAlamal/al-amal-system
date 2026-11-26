<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\HotSmsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Central notification settings: which channel each event uses. System admin only, every change audited.
 * SMS texts are fixed templates; they never include names, amounts or request details.
 */
class NotificationSettingsController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()?->isSystemAdmin(), 403);
    }

    public function index(Request $request, HotSmsService $sms): Response
    {
        $this->guard($request);
        $balance = null;
        $gatewayError = null;
        if (config('services.hotsms.enabled')) {
            try {
                $balance = $sms->balance()['provider_response'] ?? null;
            } catch (Throwable $exception) {
                $gatewayError = $exception->getMessage();
            }
        }

        $since = now()->subDays(30);

        return Inertia::render('Admin/NotificationSettings/Index', [
            'events' => DB::table('notification_settings')->orderBy('id')->get(['id', 'event_code', 'label_ar', 'description', 'in_app_enabled', 'sms_enabled', 'sms_template', 'updated_at']),
            'gateway' => ['enabled' => (bool) config('services.hotsms.enabled'), 'balance' => $balance, 'error' => $gatewayError],
            'stats' => [
                'sent30' => DB::table('notifications')->where('created_at', '>=', $since)->count(),
                'sms30' => DB::table('notifications')->where('created_at', '>=', $since)->where('sms_status', 'sent')->count(),
                'smsFailed30' => DB::table('notifications')->where('created_at', '>=', $since)->whereIn('sms_status', ['failed', 'no_phone'])->count(),
            ],
        ]);
    }

    public function update(Request $request, int $setting): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'in_app_enabled' => ['required', 'boolean'],
            'sms_enabled' => ['required', 'boolean'],
            'sms_template' => ['nullable', 'required_if:sms_enabled,true', 'string', 'min:10', 'max:300'],
        ]);
        if (! $data['in_app_enabled'] && ! $data['sms_enabled']) {
            throw ValidationException::withMessages(['in_app_enabled' => 'فعّل قناة واحدة على الأقل، أو أبقِ الإشعار داخل النظام.']);
        }

        DB::transaction(function () use ($request, $setting, $data): void {
            $before = DB::table('notification_settings')->where('id', $setting)->lockForUpdate()->first();
            abort_unless($before, 404);
            DB::table('notification_settings')->where('id', $setting)->update([
                ...$data, 'updated_by' => $request->user()->id, 'updated_at' => now(),
            ]);
            DB::table('audit_logs')->insert([
                'actor_id' => $request->user()->id, 'action' => 'notification_setting.updated',
                'subject_type' => 'notification_settings', 'subject_id' => $setting, 'ip_address' => $request->ip(),
                'metadata' => json_encode([
                    'before' => ['in_app_enabled' => (bool) $before->in_app_enabled, 'sms_enabled' => (bool) $before->sms_enabled, 'sms_template' => $before->sms_template],
                    'after' => $data,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return back()->with('success', 'حُفظت إعدادات الإشعار.');
    }
}
