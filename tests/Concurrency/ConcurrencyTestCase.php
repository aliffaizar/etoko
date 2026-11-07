<?php

namespace Tests\Concurrency;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Base class for the tests that run several real processes at once (MED-07).
 *
 * `06-conventions.md` §Testing lists "anti-overselling, **including the case
 * of two simultaneous requests**" as required coverage, and nothing in the
 * suite did it: a `grep` for `mysql|concurren|parallel|proc_open` across
 * `tests/` found one comment. The guarantee the whole of Phase 1 is built
 * around was therefore held up by a manual check nobody could repeat — and
 * three of the four HIGH findings in docs/reviews/phase-1-review.md were
 * concurrency bugs that sailed past 1,195 green tests.
 *
 * Two things make these tests different from the rest of the suite, and both
 * are forced by what they test:
 *
 * - **Separate OS processes.** A row lock only ever blocks another connection.
 *   Two requests for the last unit cannot be staged inside one PHP process, so
 *   the work runs in `workers/*.php`, each its own process with its own
 *   connection, released together by a timestamp barrier.
 * - **No wrapping transaction.** `RefreshDatabase` keeps each test inside one,
 *   which the workers could not see into; `DatabaseTruncation` commits the
 *   fixture and empties the tables afterwards instead.
 *
 * They need MySQL, because the behaviour under test is InnoDB's: SQLite locks
 * the whole file, so the same assertions there would prove nothing. On any
 * other driver each test skips itself, which keeps `php artisan test` honest
 * on the default in-memory connection and makes the documented command
 * (`DB_CONNECTION=mysql DB_DATABASE=etoko_testing php artisan test`) the one
 * that really runs them.
 */
#[Group('concurrency')]
abstract class ConcurrencyTestCase extends TestCase
{
    use DatabaseTruncation;

    /**
     * How long a worker may take before it is treated as hung.
     */
    protected const WORKER_TIMEOUT = 60;

    /**
     * How long to wait before releasing the workers, in seconds.
     *
     * Long enough for every process to boot the framework and reach its
     * barrier, short enough not to pad the suite.
     */
    protected const START_DELAY = 1.5;

    /**
     * Skip before the application is even built on a driver that cannot answer.
     *
     * Deliberately ahead of `parent::setUp()`: `DatabaseTruncation` migrates
     * and seeds as it boots, and asking it to do that against the default
     * in-memory SQLite — which these tests will not use — fails before the
     * skip would ever be reached.
     */
    protected function setUp(): void
    {
        $connection = (string) (Env::get('DB_CONNECTION') ?? '');

        if (! in_array($connection, ['mysql', 'mariadb'], strict: true)) {
            $this->markTestSkipped(self::whyItSkipped($connection === '' ? 'default' : $connection));
        }

        parent::setUp();

        $driver = $this->app['db']->connection()->getDriverName();

        if ($driver !== 'mysql') {
            $this->markTestSkipped(self::whyItSkipped($driver));
        }
    }

    private static function whyItSkipped(string $driver): string
    {
        return "Concurrency here is InnoDB behaviour and the connection is [{$driver}]. Run: "
            .'DB_CONNECTION=mysql DB_DATABASE=etoko_testing php artisan test --compact';
    }

    /**
     * Run one worker script in `$copies` processes, all released together.
     *
     * Returns one decoded result per process, in completion order — which is
     * deliberately not the order they were started in, because which one won
     * is the thing being measured.
     *
     * @param  array<int, string|int|float>  $arguments  Appended after the barrier timestamp.
     * @return array<int, array<string, mixed>>
     */
    protected function runWorkers(string $script, array $arguments, int $copies): array
    {
        $startAt = microtime(true) + self::START_DELAY;

        $processes = [];

        foreach (range(1, $copies) as $copy) {
            $process = new Process(
                [PHP_BINARY, $this->workerPath($script), (string) $startAt, ...array_map('strval', $arguments)],
                base_path(),
                $this->workerEnvironment(),
                null,
                self::WORKER_TIMEOUT,
            );

            $process->start();
            $processes[] = $process;
        }

        $results = [];

        foreach ($processes as $process) {
            $process->wait();

            $results[] = $this->decodeWorkerOutput($process);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeWorkerOutput(Process $process): array
    {
        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        $decoded = $lines === [] ? null : json_decode((string) end($lines), associative: true);

        if (! is_array($decoded)) {
            $this->fail(sprintf(
                "A concurrency worker reported nothing usable.\nexit: %d\nstdout: %s\nstderr: %s",
                (int) $process->getExitCode(),
                $process->getOutput(),
                $process->getErrorOutput(),
            ));
        }

        return $decoded;
    }

    /**
     * The database the test is connected to, handed to the workers.
     *
     * Passed explicitly rather than inherited: the workers read `.env` like any
     * other process would, and `.env` points at the development database.
     * Laravel's loader does not overwrite variables that are already set, so
     * these win — and the one database nobody may touch stays untouched.
     *
     * @return array<string, string|null>
     */
    private function workerEnvironment(): array
    {
        $connection = $this->app['db']->connection();
        $config = $connection->getConfig();

        return [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => $connection->getName(),
            'DB_HOST' => $config['host'] ?? null,
            'DB_PORT' => (string) ($config['port'] ?? ''),
            'DB_DATABASE' => $config['database'] ?? null,
            'DB_USERNAME' => $config['username'] ?? null,
            'DB_PASSWORD' => $config['password'] ?? null,
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
        ];
    }

    private function workerPath(string $script): string
    {
        return __DIR__.'/workers/'.$script;
    }

    /**
     * How many of the workers reported success.
     *
     * @param  array<int, array<string, mixed>>  $results
     */
    protected function countSucceeded(array $results): int
    {
        return count(array_filter($results, fn (array $result): bool => ($result['ok'] ?? false) === true));
    }

    /**
     * The exception classes the refused workers reported, as a list.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, string>
     */
    protected function refusalsIn(array $results): array
    {
        return array_values(array_map(
            fn (array $result): string => (string) ($result['error'] ?? ''),
            array_filter($results, fn (array $result): bool => ($result['ok'] ?? false) !== true),
        ));
    }
}
