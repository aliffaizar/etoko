<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderAwaitingBranchNotification;

/*
 * The notification centre and the panel's bell (P2-E6-T4).
 *
 * A notification belongs to one user; the relationship is the only scope, so
 * somebody else's id is simply not found.
 */

beforeEach(function () {
    $this->branch = Branch::factory()->default()->create();
    $this->admin = User::factory()->adminCabang($this->branch)->create();
    $this->order = Order::factory()->forBranch($this->branch)->paid()->create();
});

function notifyAboutOrder(User $user, Order $order): string
{
    $user->notify(new OrderAwaitingBranchNotification($order));

    return $user->notifications()->latest()->firstOrFail()->id;
}

it('lists the signed-in user notifications with a link to act on them', function () {
    notifyAboutOrder($this->admin, $this->order);
    notifyAboutOrder(User::factory()->adminCabang($this->branch)->create(), $this->order);

    $this->actingAs($this->admin)
        ->get(route('branch.notifications.index'))
        ->assertInertia(fn ($page) => $page
            ->component('branch/notifications/index')
            ->has('notifications.data', 1)
            ->where('notifications.data.0.title', 'Pesanan perlu diproses')
            ->where('notifications.data.0.url', route('admin.orders.show', $this->order))
            ->where('notifications.data.0.is_read', false)
            ->where('unread_count', 1));
});

it('narrows the list to unread notifications', function () {
    notifyAboutOrder($this->admin, $this->order);
    $this->admin->notifications()->first()->markAsRead();
    notifyAboutOrder($this->admin, $this->order);

    $this->actingAs($this->admin)
        ->get(route('branch.notifications.index', ['filter' => 'unread']))
        ->assertInertia(fn ($page) => $page->has('notifications.data', 1)->where('filter', 'unread'));
});

it('marks a notification read on the way to what it is about', function () {
    $id = notifyAboutOrder($this->admin, $this->order);

    $this->actingAs($this->admin)
        ->post(route('branch.notifications.open', $id))
        ->assertRedirect(route('admin.orders.show', $this->order));

    expect($this->admin->unreadNotifications()->count())->toBe(0);
});

it('does not open somebody else notification', function () {
    $id = notifyAboutOrder(User::factory()->adminCabang($this->branch)->create(), $this->order);

    $this->actingAs($this->admin)
        ->post(route('branch.notifications.open', $id))
        ->assertNotFound();
});

it('marks everything read at once', function () {
    notifyAboutOrder($this->admin, $this->order);
    notifyAboutOrder($this->admin, $this->order);

    $this->actingAs($this->admin)
        ->from(route('branch.notifications.index'))
        ->post(route('branch.notifications.read'))
        ->assertRedirect(route('branch.notifications.index'));

    expect($this->admin->unreadNotifications()->count())->toBe(0);
});

it('feeds the bell on every panel page for staff', function () {
    notifyAboutOrder($this->admin, $this->order);

    $this->actingAs($this->admin)
        ->get(route('admin.orders.index'))
        ->assertInertia(fn ($page) => $page
            ->where('notificationBell.unread', 1)
            ->has('notificationBell.recent', 1));
});

it('gives a customer no bell and no notification centre', function () {
    $customer = User::factory()->customer()->create();

    $this->actingAs($customer)
        ->get(route('shop.account.orders.index'))
        ->assertInertia(fn ($page) => $page->where('notificationBell', null));

    $this->actingAs($customer)->get(route('branch.notifications.index'))->assertForbidden();
});
