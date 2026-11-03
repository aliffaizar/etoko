<?php

use App\Actions\User\UpdateUser;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;

beforeEach(function () {
    $this->action = app(UpdateUser::class);
});

it('replaces the single application role', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->kasir($branch)->create();

    $this->action->handle($user, [
        'name' => $user->name,
        'email' => $user->email,
        'role' => UserRole::StaffGudang->value,
        'branch_id' => $branch->id,
    ]);

    expect($user->refresh()->getRoleNames()->all())->toBe([UserRole::StaffGudang->value]);
});

it('drops the branch when the role no longer needs one', function () {
    $branch = Branch::factory()->create();
    $user = User::factory()->adminCabang($branch)->create();

    $this->action->handle($user, [
        'name' => $user->name,
        'email' => $user->email,
        'role' => UserRole::AdminPusat->value,
        'branch_id' => $branch->id,
    ]);

    expect($user->refresh()->branch_id)->toBeNull();
});

it('moves a branch account to another branch', function () {
    $from = Branch::factory()->create();
    $to = Branch::factory()->create();
    $user = User::factory()->kasir($from)->create();

    $this->action->handle($user, [
        'name' => $user->name,
        'email' => $user->email,
        'role' => UserRole::Kasir->value,
        'branch_id' => $to->id,
    ]);

    expect($user->refresh()->branch_id)->toBe($to->id);
});

it('keeps the account usable after an administrator changes the email', function () {
    $user = User::factory()->adminPusat()->create();

    $this->action->handle($user, [
        'name' => $user->name,
        'email' => 'alamat-baru@etoko.test',
        'role' => UserRole::AdminPusat->value,
    ]);

    $user->refresh();

    expect($user->email)->toBe('alamat-baru@etoko.test')
        ->and($user->email_verified_at)->not->toBeNull();
});

it('leaves the active state untouched when it is not given', function () {
    $user = User::factory()->adminPusat()->inactive()->create();

    $this->action->handle($user, [
        'name' => $user->name,
        'email' => $user->email,
        'role' => UserRole::AdminPusat->value,
    ]);

    expect($user->refresh()->is_active)->toBeFalse();
});
