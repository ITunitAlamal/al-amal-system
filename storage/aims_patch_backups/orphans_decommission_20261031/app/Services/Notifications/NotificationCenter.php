<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\HotSmsService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * One place that notifies users: a persistent in-app notification (read / unread) and, when the
 * central settings enable it for the event, an SMS with a fixed template that carries no request details.
 *
 * A dedupe key makes each event occurrence notify a recipient once, even when the caller retries.
 * Notifications written inside a transaction disappear with it; the SMS is sent only after the
 * request (or console command) finishes, and only if its notification row still exists.
 */
final class NotificationCenter
{
    private const TYPE_IN_APP = 'aims';

    private const TYPE_SMS_ONLY = 'aims.sms_only';

    /** @var array<string, object|null> */
    private array $settings = [];

    /**
     * Notices of work waiting for the user (assigned tasks, returns, overdue work, delegation changes): a user cannot
     * mute them in their preferences. Only the system administrator turns an event off for everyone.
     */
    public const MANDATORY_EVENTS = [
        'workflow_task_assigned', 'workflow_task_urgent', 'workflow_task_overdue', 'workflow_request_returned',
        'approval_delegation_issued', 'approval_delegation_revoked', 'correspondence.assigned', 'orphans_emergency_flagged',
    ];

    /** @var array<int, list<string>> */
    private array $muted = [];

    public function __construct(private readonly HotSmsService $sms) {}

    /**
     * Notification types the user chose not to receive.
     *
     * @return list<string>
     */
    public function mutedFor(int $userId): array
    {
        if (! array_key_exists($userId, $this->muted)) {
            $value = Schema::hasTable('user_notification_preferences')
                ? DB::table('user_notification_preferences')->where('user_id', $userId)->value('muted_events') : null;
            $this->muted[$userId] = is_string($value) ? array_values(array_filter((array) json_decode($value, true), 'is_string')) : [];
        }

        return $this->muted[$userId];
    }

    /**
     * Types a user may receive, with whether they muted each and whether it can be muted at all.
     *
     * @return list<array{code: string, label: string, description: ?string, muted: bool, mandatory: bool}>
     */
    public function preferencesFor(int $userId): array
    {
        $muted = $this->mutedFor($userId);

        return DB::table('notification_settings')->where('in_app_enabled', true)->orderBy('label_ar')->get(['event_code', 'label_ar', 'description'])
            ->map(fn (object $row): array => ['code' => $row->event_code, 'label' => $row->label_ar, 'description' => $row->description,
                'muted' => in_array($row->event_code, $muted, true), 'mandatory' => in_array($row->event_code, self::MANDATORY_EVENTS, true)])
            ->values()->all();
    }

    /**
     * @param  list<string>  $events  types to mute; mandatory or unknown ones are ignored
     */
    public function savePreferences(int $userId, array $events): void
    {
        $known = DB::table('notification_settings')->pluck('event_code')->all();
        $muted = array_values(array_unique(array_filter($events, fn ($event): bool => is_string($event) && in_array($event, $known, true) && ! in_array($event, self::MANDATORY_EVENTS, true))));
        DB::table('user_notification_preferences')->upsert(
            [['user_id' => $userId, 'muted_events' => json_encode($muted), 'created_at' => now(), 'updated_at' => now()]],
            ['user_id'], ['muted_events', 'updated_at']
        );
        $this->muted[$userId] = $muted;
    }

    /**
     * @param  array{title: string, body?: ?string, url?: ?string, dedupe?: ?string}  $payload
     * @return bool true when a new notification was recorded
     */
    public function notify(int $userId, string $event, array $payload): bool
    {
        $setting = $this->setting($event);
        if (! $setting || (! $setting->in_app_enabled && ! $setting->sms_enabled)) {
            return false;
        }
        // A user may mute a notification type, but never one that tells them about work waiting for them.
        if (! in_array($event, self::MANDATORY_EVENTS, true) && in_array($event, $this->mutedFor($userId), true)) {
            return false;
        }
        if (! DB::table('users')->where('id', $userId)->where('is_active', true)->exists()) {
            return false;
        }

        $id = (string) Str::uuid();
        $inserted = DB::table('notifications')->insertOrIgnore([
            'id' => $id,
            'type' => $setting->in_app_enabled ? self::TYPE_IN_APP : self::TYPE_SMS_ONLY,
            'notifiable_type' => User::class,
            'notifiable_id' => $userId,
            'event_code' => $event,
            'dedupe_key' => $payload['dedupe'] ?? null,
            'data' => json_encode([
                'title' => $payload['title'],
                'body' => $payload['body'] ?? null,
                'url' => $this->internalUrl($payload['url'] ?? null),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            // An SMS-only record is kept for the audit and dedupe, but never listed as unread.
            'read_at' => $setting->in_app_enabled ? null : now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted === 0) {
            return false;
        }

        if ($setting->sms_enabled && $setting->sms_template) {
            $this->queueSms($id, $userId, (string) $setting->sms_template);
        }

        return true;
    }

    /**
     * @param  iterable<int>  $userIds
     * @param  array{title: string, body?: ?string, url?: ?string, dedupe?: ?string}  $payload  dedupe is suffixed per recipient
     */
    public function notifyMany(iterable $userIds, string $event, array $payload): int
    {
        $count = 0;
        foreach (collect($userIds)->map(fn ($id): int => (int) $id)->unique() as $userId) {
            $count += (int) $this->notify($userId, $event, [
                ...$payload,
                'dedupe' => isset($payload['dedupe']) ? $payload['dedupe'].':u'.$userId : null,
            ]);
        }

        return $count;
    }

    /**
     * Notify every active holder of a role, in any department.
     *
     * @param  array{title: string, body?: ?string, url?: ?string, dedupe?: ?string}  $payload
     */
    public function notifyRole(string $roleCode, string $event, array $payload): int
    {
        $userIds = DB::table('department_user_roles as a')
            ->join('roles as r', 'r.id', '=', 'a.role_id')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('r.code', $roleCode)->where('u.is_active', true)
            ->distinct()->pluck('a.user_id');

        return $this->notifyMany($userIds, $event, $payload);
    }

    public function unreadCount(User $user): int
    {
        return $this->visible($user)->whereNull('read_at')->count();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function recent(User $user, int $limit = 8): Collection
    {
        return $this->visible($user)->orderByDesc('created_at')->limit($limit)
            ->get(['id', 'event_code', 'data', 'read_at', 'created_at'])
            ->map(fn (object $row): array => $this->present($row));
    }

    /**
     * @return array<string, mixed>
     */
    public function present(object $row): array
    {
        $data = json_decode((string) $row->data, true) ?: [];

        return [
            'id' => $row->id,
            'event' => $row->event_code,
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? null,
            'url' => $data['url'] ?? null,
            'read' => $row->read_at !== null,
            'createdAt' => (string) $row->created_at,
        ];
    }

    /**
     * Marks one of the user's own notifications as read and returns its link (null when not found).
     */
    public function open(User $user, string $id): ?string
    {
        $row = $this->visible($user, includeCorrespondence: true)->where('id', $id)->first(['id', 'data', 'read_at']);
        if (! $row) {
            return null;
        }
        if ($row->read_at === null) {
            DB::table('notifications')->where('id', $row->id)->update(['read_at' => now(), 'updated_at' => now()]);
        }

        return json_decode((string) $row->data, true)['url'] ?? '';
    }

    public function markAllRead(User $user): int
    {
        return $this->visible($user)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
    }

    public function visible(User $user, bool $includeCorrespondence = false): Builder
    {
        return DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', self::TYPE_IN_APP)
            ->when(! $includeCorrespondence, fn (Builder $query) => $query
                ->where(fn (Builder $events) => $events->whereNull('event_code')->orWhere('event_code', 'not like', 'correspondence.%')));
    }

    private function setting(string $event): ?object
    {
        return $this->settings[$event] ??= DB::table('notification_settings')->where('event_code', $event)->first();
    }

    /** Only same-site relative links are stored, so a notification can never redirect elsewhere. */
    private function internalUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);

        return str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path.($query ? '?'.$query : '') : null;
    }

    private function queueSms(string $notificationId, int $userId, string $template): void
    {
        $send = function () use ($notificationId, $userId, $template): void {
            if (! DB::table('notifications')->where('id', $notificationId)->exists()) {
                return; // the transaction that created it was rolled back
            }
            $phone = DB::table('employees')->where('user_id', $userId)->whereNull('deleted_at')->value('phone');
            if (! $phone) {
                $this->smsResult($notificationId, 'no_phone');

                return;
            }
            try {
                $this->sms->send((string) $phone, $template);
                $this->smsResult($notificationId, 'sent');
            } catch (Throwable $exception) {
                Log::warning('Notification SMS failed', ['notification' => $notificationId, 'error' => $exception->getMessage()]);
                $this->smsResult($notificationId, 'failed');
            }
        };

        // Runs after the response (or at the end of the console command): no queue worker is needed,
        // and the user never waits for the SMS gateway.
        app()->terminating($send);
    }

    private function smsResult(string $notificationId, string $status): void
    {
        DB::table('notifications')->where('id', $notificationId)->update([
            'sms_status' => $status,
            'sms_sent_at' => $status === 'sent' ? now() : null,
            'updated_at' => now(),
        ]);
    }
}
