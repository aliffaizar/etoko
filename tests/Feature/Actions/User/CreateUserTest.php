<?php

use App\Actions\User\CreateUser;
use App\Enums\UserRole;
use App\Models\Branch;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->action = app(CreateUser::class);
});

it('creates a branch account with its branch and role', function () {
    $branch = Branch::factory()->create();

    $user = $this->action->handle([
        'name' => 'Eka Gudang',
        'email' => 'eka@etoko.test',
        'phone' => '081200112233',
        'role' => UserRole::StaffGudang->value,
        'branch_id' => $branch->id,
        'password' => 'rahasia-kuat-123',
    ]);

    expect($user->branch_id)->toBe($branch->id)
        ->and($user->hasAppRole(UserRole::StaffGudang))->toBeTrue()
        ->and($user->is_active)->toBeTrue()
        ->and(Hash::check('rahasia-kuat-123', $user->password))->toBeTrue();
});

it('ignores a branch for a central role', function () {
    $branch = Branch::factory()->create();

    $user = $this->action->handle([
        'name' => 'Fajar Pusat',
        'email' => 'fajar@etoko.test',
        'role' => UserRole::AdminPusat->value,
        'branch_id' => $branch->id,
        'password' => 'rahasia-kuat-123',
    ]);

    expect($user->branch_id)->toBeNull();
});

it('marks the account verified so the staff member can reach the panel', function () {
    $user = $this->action->handle([
        'name' => 'Gita Pusat',
        'email' => 'gita@etoko.test',
        'role' => UserRole::AdminPusat->value,
        'password' => 'rahasia-kuat-123',
    ]);

    expect($user->email_verified_at)->not->toBeNull();
});

it('can create an account that is inactive from the start', function () {
    $user = $this->action->handle([
        'name' => 'Hadi Nonaktif',
        'email' => 'hadi@etoko.test',
        'role' => UserRole::AdminPusat->value,
        'is_active' => false,
        'password' => 'rahasia-kuat-123',
    ]);

    expect($user->is_active)->toBeFalse();
});

it('records the creation in the audit log', function () {
    $user = $this->action->handle([
        'name' => 'Ina Pusat',
        'email' => 'ina@etoko.test',
        'role' => UserRole::AdminPusat->value,
        'password' => 'rahasia-kuat-123',
    ]);

    expect($user->auditLogs()->where('event', 'created')->count())->toBe(1);
});
