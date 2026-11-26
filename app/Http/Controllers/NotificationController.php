<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's own notifications. Every query is scoped to the user, so another
 * person's notification id simply is not found (no IDOR).
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationCenter $notifications) {}

    public function index(Request $request): Response
    {
        $filter = $request->validate(['filter' => ['nullable', 'in:all,unread']])['filter'] ?? 'all';
        $page = $this->notifications->visible($request->user())
            ->when($filter === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->orderByDesc('created_at')
            ->paginate(20, ['id', 'event_code', 'data', 'read_at', 'created_at'])
            ->withQueryString()
            ->through(fn (object $row): array => $this->notifications->present($row));

        return Inertia::render('Notifications/Index', [
            'notifications' => $page,
            'filter' => $filter,
            'unread' => $this->notifications->unreadCount($request->user()),
            'preferences' => $this->notifications->preferencesFor((int) $request->user()->id),
        ]);
    }

    /** The notification types the user chose not to receive (work notices cannot be muted). */
    public function preferences(Request $request): RedirectResponse
    {
        $data = $request->validate(['muted' => ['present', 'array', 'max:100'], 'muted.*' => ['string', 'max:100']]);
        $this->notifications->savePreferences((int) $request->user()->id, $data['muted']);

        return back()->with('success', 'حُفظت تفضيلات الإشعارات.');
    }

    /** Latest notifications for the bell menu. */
    public function recent(Request $request): JsonResponse
    {
        return response()->json([
            'items' => $this->notifications->recent($request->user()),
            'unread' => $this->notifications->unreadCount($request->user()),
        ]);
    }

    /** Marks the notification read and goes to what it points at. */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $url = $this->notifications->open($request->user(), $notification);
        abort_if($url === null, 404);

        return $url !== '' ? redirect($url) : redirect()->route('notifications.index');
    }

    public function readAll(Request $request): RedirectResponse
    {
        $count = $this->notifications->markAllRead($request->user());

        return back()->with('success', $count ? 'عُلّمت كل الإشعارات كمقروءة.' : 'لا توجد إشعارات غير مقروءة.');
    }
}
