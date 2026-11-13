<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\NotificationPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in user's own notifications (P1-E4-T9, P2-E6-T4).
 *
 * No policy and no permission: a notification belongs to exactly one user, and
 * the relationship is what scopes it — another user's notification id is not
 * found, because it is looked up through `$actor->notifications()`.
 *
 * Lives under `/branch` because that group already admits every staff
 * account, central or branch (`EnsureBranchAccess`), and the bell is drawn in
 * both panels. The bell itself is fed by a shared prop
 * (`HandleInertiaRequests`); this is the full list behind it.
 */
class NotificationController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $unreadOnly = $request->query('filter') === 'unread';

        $notifications = ($unreadOnly ? $actor->unreadNotifications() : $actor->notifications())
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (DatabaseNotification $notification): array => NotificationPresenter::item($notification));

        return Inertia::render('branch/notifications/index', [
            'notifications' => $notifications,
            'filter' => $unreadOnly ? 'unread' : 'all',
            'unread_count' => $actor->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark one notification read and take the user to what it is about.
     *
     * A POST rather than a link straight to the target, so opening it is what
     * marks it read. A notification with nothing to open goes back.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var DatabaseNotification $found */
        $found = $actor->notifications()->whereKey($notification)->firstOrFail();
        $found->markAsRead();

        $url = NotificationPresenter::item($found)['url'];

        return $url === null ? back() : redirect()->to($url);
    }

    public function markAllRead(): RedirectResponse
    {
        /** @var User $actor */
        $actor = request()->user();

        $marked = $actor->unreadNotifications()->update(['read_at' => now()]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $marked === 0
                ? 'Tidak ada notifikasi baru.'
                : "{$marked} notifikasi ditandai sudah dibaca.",
        ]);

        return back();
    }
}
