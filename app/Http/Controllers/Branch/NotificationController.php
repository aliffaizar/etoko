<?php

namespace App\Http\Controllers\Branch;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Marking the signed-in user's own notifications as read (P1-E4-T9).
 *
 * No policy and no permission: a notification belongs to exactly one user, and
 * the relationship is what scopes it — there is no way to name somebody
 * else's. Only "read everything" is offered, because that is the whole of what
 * the dashboard feed needs; per-notification state can come when there is a
 * screen that wants it.
 */
class NotificationController extends Controller
{
    public function markAllRead(): RedirectResponse
    {
        /** @var User $actor */
        $actor = request()->user();

        $marked = $actor->unreadNotifications()->count();
        $actor->unreadNotifications->markAsRead();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $marked === 0
                ? 'Tidak ada notifikasi baru.'
                : "{$marked} notifikasi ditandai sudah dibaca.",
        ]);

        return back();
    }
}
