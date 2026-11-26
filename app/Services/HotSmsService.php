<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class HotSmsService
{
    private function settings(): array
    {
        $config = config('services.hotsms', []);

        if (! ($config['enabled'] ?? false)) {
            throw new RuntimeException(
                'إرسال SMS معطّل حاليًا في إعدادات النظام.'
            );
        }

        $username = trim((string) ($config['username'] ?? ''));
        $password = (string) ($config['password'] ?? '');
        $sender = trim((string) ($config['sender'] ?? ''));

        if ($username === '' || $password === '' || $sender === '') {
            throw new RuntimeException(
                'إعدادات HotSMS غير مكتملة.'
            );
        }

        return [
            'base_url' => rtrim(
                (string) ($config['base_url'] ?? ''),
                '/'
            ),
            'username' => $username,
            'password' => $password,
            'sender' => $sender,
        ];
    }

    public function send(string $mobile, string $message): array
    {
        $settings = $this->settings();

        $mobile = trim($mobile);

        if ($mobile === '' || trim($message) === '') {
            throw new RuntimeException(
                'رقم الجوال ونص الرسالة مطلوبان.'
            );
        }

        if (! str_starts_with($settings['base_url'], 'https://')) {
            throw new RuntimeException(
                'يجب استخدام HTTPS مع بوابة HotSMS.'
            );
        }

        // لا نستخدم retry تلقائيًا حتى لا نكرر إرسال SMS
        // عند حدوث خطأ اتصال بعد استقبال المزود للطلب.
        $response = Http::timeout(15)
            ->accept('text/plain')
            ->get($settings['base_url'].'/sendbulksms.php', [
                'user_name' => $settings['username'],
                'user_pass' => $settings['password'],
                'sender' => $settings['sender'],
                'mobile' => $mobile,
                'type' => 0,
                'text' => $message,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'تعذر الاتصال ببوابة الرسائل. رمز HTTP: '
                .$response->status()
            );
        }

        // لا نفترض أن HTTP 200 يعني قبول الرسالة.
        // سنحدد تفسير استجابة المزود بعد توثيقها.
        return [
            'http_status' => $response->status(),
            'provider_response' => trim($response->body()),
            'delivery_confirmed' => false,
        ];
    }

    public function balance(): array
    {
        $settings = $this->settings();

        if (! str_starts_with($settings['base_url'], 'https://')) {
            throw new RuntimeException(
                'يجب استخدام HTTPS مع بوابة HotSMS.'
            );
        }

        $response = Http::timeout(15)
            ->accept('text/plain')
            ->get($settings['base_url'].'/getbalance.php', [
                'user_name' => $settings['username'],
                'user_pass' => $settings['password'],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'تعذر الاستعلام عن رصيد الرسائل.'
            );
        }

        return [
            'http_status' => $response->status(),
            'provider_response' => trim($response->body()),
        ];
    }
}
