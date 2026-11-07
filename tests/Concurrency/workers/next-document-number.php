<?php

/**
 * Take one document number from a counter (HIGH-02).
 *
 * Usage: next-document-number.php <startAt> <prefix>
 */

require __DIR__.'/bootstrap.php';

use App\Support\DocumentNumber;

[$startAt, $prefix] = [(float) $argv[1], (string) $argv[2]];

awaitStartSignal($startAt);

reportOutcomeOf(fn (): array => ['number' => DocumentNumber::next($prefix)]);
