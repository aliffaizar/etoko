<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Boots the application inside a concurrency worker process.
 *
 * The workers are separate OS processes on purpose: a row lock only blocks a
 * *different* connection, so two requests racing for the last unit cannot be
 * staged inside one PHP process at all. This is the thing the project's own
 * conventions ask for under §Testing — "anti-overselling, including the case
 * of two simultaneous requests" — and it is what caught HIGH-01, HIGH-02 and
 * HIGH-03, none of which the 1,195 single-threaded tests noticed.
 *
 * The parent passes the database credentials through the environment, and
 * Laravel's `.env` loader is immutable, so those win: every worker talks to
 * the same database the test is using.
 */

require __DIR__.'/../../../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../../../bootstrap/app.php';

$app->make(Kernel::class)->bootstrap();

/**
 * Hold every worker until the same moment, so they really do collide.
 *
 * Without a barrier the processes start milliseconds apart — enough for the
 * first to finish before the second begins, which is the sequential case the
 * rest of the suite already covers.
 */
function awaitStartSignal(float $startAt): void
{
    $wait = $startAt - microtime(true);

    if ($wait > 0) {
        usleep((int) ($wait * 1_000_000));
    }
}

/**
 * Report this worker's outcome on stdout, one JSON object per process.
 *
 * @param  array<string, mixed>  $result
 */
function reportResult(array $result): void
{
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE).PHP_EOL);
}

/**
 * Run the worker's work, reporting a thrown exception rather than dying on it.
 *
 * A refusal is a *result* here, not a failure: "exactly one of these two
 * succeeded and the other was refused" is the assertion, so the exception's
 * class has to make it back to the parent.
 *
 * @param  callable(): array<string, mixed>  $work
 */
function reportOutcomeOf(callable $work): void
{
    try {
        reportResult(['ok' => true] + $work());
    } catch (Throwable $refused) {
        reportResult([
            'ok' => false,
            'error' => $refused::class,
            'message' => $refused->getMessage(),
        ]);
    }
}
