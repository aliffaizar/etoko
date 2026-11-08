<?php

/**
 * Settle one payment, the way the panel's verify endpoint does.
 *
 * Usage: verify-payment.php <startAt> <paymentId> <actorId>
 */

require __DIR__.'/bootstrap.php';

use App\Actions\Payment\VerifyPayment;
use App\Models\Payment;
use App\Models\User;
use App\Scopes\BranchScope;

[$startAt, $paymentId, $actorId] = [(float) $argv[1], (int) $argv[2], (int) $argv[3]];

$payment = Payment::withoutGlobalScope(BranchScope::class)->findOrFail($paymentId);
$actor = User::findOrFail($actorId);

awaitStartSignal($startAt);

reportOutcomeOf(fn (): array => ['settled' => app(VerifyPayment::class)->handle($payment, $actor)]);
