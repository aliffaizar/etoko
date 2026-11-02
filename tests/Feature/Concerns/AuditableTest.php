<?php

use App\Models\AuditLog;
use App\Models\User;

it('records a created event with the new attributes', function () {
    $user = User::factory()->create(['name' => 'Budi']);

    $log = AuditLog::where('auditable_id', $user->id)->sole();

    expect($log->event)->toBe(AuditLog::EVENT_CREATED)
        ->and($log->auditable_type)->toBe(User::class)
        ->and($log->old_values)->toBeNull()
        ->and($log->new_values['name'])->toBe('Budi');
});

it('records an updated event with only the changed attributes', function () {
    $user = User::factory()->create(['name' => 'Budi']);

    $user->update(['name' => 'Budi Santoso']);

    $log = AuditLog::where('event', AuditLog::EVENT_UPDATED)->sole();

    expect($log->old_values)->toBe(['name' => 'Budi'])
        ->and($log->new_values)->toBe(['name' => 'Budi Santoso']);
});

it('does not record an update that changes nothing', function () {
    $user = User::factory()->create(['name' => 'Budi']);

    $user->update(['name' => 'Budi']);

    expect(AuditLog::where('event', AuditLog::EVENT_UPDATED)->count())->toBe(0);
});

it('keeps the password out of the audit log', function () {
    $user = User::factory()->create();

    $user->update(['password' => 'new-password']);

    expect(AuditLog::pluck('new_values')->flatten(1)->all())
        ->each(fn ($values) => $values->not->toHaveKey('password'));
});

it('records a deleted event with the previous attributes', function () {
    $user = User::factory()->create(['name' => 'Budi']);

    $user->delete();

    $log = AuditLog::where('event', AuditLog::EVENT_DELETED)->sole();

    expect($log->old_values['name'])->toBe('Budi');
});

it('attributes the change to the acting user', function () {
    $actor = User::factory()->create();

    $this->actingAs($actor);
    $subject = User::factory()->create();

    $log = AuditLog::where('auditable_id', $subject->id)->sole();

    expect($log->user_id)->toBe($actor->id);
});
