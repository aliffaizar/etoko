<?php

use App\Models\Branch;
use App\Models\User;

/*
 * Weekly opening hours (P1-E2-T4).
 */

function weekPayload(array $overrides = []): array
{
    $hours = array_map(fn (int $day): array => [
        'day_of_week' => $day,
        'opens_at' => '08:00',
        'closes_at' => '17:00',
        'is_closed' => false,
    ], range(0, 6));

    foreach ($overrides as $day => $values) {
        $hours[$day] = [...$hours[$day], ...$values];
    }

    return ['hours' => $hours];
}

it('saves the whole week at once', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.operating-hours.update', $branch), weekPayload([
            0 => ['is_closed' => true],
            6 => ['opens_at' => '10:00', 'closes_at' => '15:00'],
        ]))
        ->assertRedirect();

    $hours = $branch->operatingHours()->orderBy('day_of_week')->get();

    expect($hours)->toHaveCount(7)
        ->and($hours[0]->is_closed)->toBeTrue()
        ->and($hours[0]->opensAtTime())->toBeNull()
        ->and($hours[1]->opensAtTime())->toBe('08:00')
        ->and($hours[6]->opensAtTime())->toBe('10:00')
        ->and($hours[6]->closesAtTime())->toBe('15:00');
});

it('updates the existing rows instead of adding new ones', function () {
    $branch = Branch::factory()->create();
    $branch->operatingHours()->createMany(array_map(fn (int $day): array => [
        'day_of_week' => $day,
        'opens_at' => '07:00:00',
        'closes_at' => '12:00:00',
        'is_closed' => false,
    ], range(0, 6)));

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.operating-hours.update', $branch), weekPayload());

    expect($branch->operatingHours()->count())->toBe(7)
        ->and($branch->operatingHours()->where('day_of_week', 2)->first()?->opensAtTime())->toBe('08:00');
});

it('clears the times of a day marked closed', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.operating-hours.update', $branch), weekPayload([
            3 => ['is_closed' => true, 'opens_at' => '08:00', 'closes_at' => '17:00'],
        ]));

    $wednesday = $branch->operatingHours()->where('day_of_week', 3)->sole();

    expect($wednesday->is_closed)->toBeTrue()
        ->and($wednesday->opens_at)->toBeNull()
        ->and($wednesday->closes_at)->toBeNull();
});

it('refuses an open day without both times', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.operating-hours.update', $branch), weekPayload([
            2 => ['opens_at' => null, 'closes_at' => null],
        ]))
        ->assertSessionHasErrors('hours.2.opens_at');

    expect($branch->operatingHours()->count())->toBe(0);
});

it('refuses a closing time that is not after the opening time', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.operating-hours.update', $branch), weekPayload([
            4 => ['opens_at' => '17:00', 'closes_at' => '08:00'],
        ]))
        ->assertSessionHasErrors('hours.4.closes_at');
});

it('refuses a partial week', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.operating-hours.update', $branch), [
            'hours' => [['day_of_week' => 1, 'opens_at' => '08:00', 'closes_at' => '17:00', 'is_closed' => false]],
        ])
        ->assertSessionHasErrors('hours');
});

it('refuses a malformed time', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->adminPusat()->create())
        ->put(route('admin.branches.operating-hours.update', $branch), weekPayload([
            1 => ['opens_at' => '25:99'],
        ]))
        ->assertSessionHasErrors('hours.1.opens_at');
});

it('lets a branch admin set the hours of their own branch only', function () {
    $branch = Branch::factory()->create();
    $other = Branch::factory()->create();
    $actor = User::factory()->adminCabang($branch)->create();

    $this->actingAs($actor)
        ->put(route('admin.branches.operating-hours.update', $branch), weekPayload())
        ->assertRedirect();

    $this->actingAs($actor)
        ->put(route('admin.branches.operating-hours.update', $other), weekPayload())
        ->assertForbidden();

    expect($other->operatingHours()->count())->toBe(0);
});

it('forbids a warehouse staffer from touching the hours', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(User::factory()->staffGudang($branch)->create())
        ->put(route('admin.branches.operating-hours.update', $branch), weekPayload())
        ->assertForbidden();
});
