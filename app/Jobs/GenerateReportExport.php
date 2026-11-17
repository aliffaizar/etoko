<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\ReportExportReady;
use App\Services\Branch\BranchContext;
use App\Services\Reporting\ReportCatalog;
use App\Services\Reporting\ReportExporter;
use App\Services\Reporting\ReportFilters;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Builds a large report export off the request and tells its owner when the
 * file is ready (P2-E7-T9).
 *
 * A queued job has no request, so it has no branch context either — run as
 * is, a branch admin's export would read **every** branch. The job therefore
 * restricts the context to the owner's branch itself before building the
 * report, exactly as `SetBranchContext` would have for the screen (BR-3), and
 * checks the owner may still export the report: a permission taken away
 * between the click and the run is honoured.
 *
 * Files live on the private `local` disk under the owner's own folder, which
 * is what the download route checks; files older than `KEEP_DAYS` in that
 * folder are cleared on the way.
 */
class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    /** Folder on the `local` disk the exports are kept in. */
    public const DIRECTORY = 'report-exports';

    public const KEEP_DAYS = 7;

    /**
     * @param  array<string, string>  $filters  The report's query string, as `ReportFilters::toQuery()` gave it.
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $report,
        public readonly array $filters,
        public readonly string $format,
    ) {}

    public function handle(BranchContext $context, ReportExporter $exporter): void
    {
        $user = User::query()->find($this->userId);

        // A branch-bound account without a branch is refused by the `branch`
        // middleware on every screen; here it must not fall through to an
        // unrestricted read.
        if ($user === null
            || ! ReportCatalog::mayExport($user, $this->report)
            || (! $user->isCentral() && $user->branch_id === null)) {
            return;
        }

        $build = function () use ($user, $exporter): void {
            $table = ReportCatalog::make($this->report, ReportFilters::fromInput($this->filters, $user))->table();
            $file = Str::lower(Str::random(12)).'-'.$exporter->fileName($table, $this->format);

            $exporter->store($table, $this->format, 'local', self::pathFor($user, $file));

            $user->notify(new ReportExportReady($table->title, $this->format, $file));
        };

        $user->isCentral()
            ? $context->withoutRestriction($build)
            : $context->forBranch((int) $user->branch_id, $build);

        $this->pruneOldExports($user);
    }

    public static function pathFor(User $user, string $file): string
    {
        return self::DIRECTORY.'/'.$user->getKey().'/'.$file;
    }

    private function pruneOldExports(User $user): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDays(self::KEEP_DAYS)->getTimestamp();

        foreach ($disk->files(self::DIRECTORY.'/'.$user->getKey()) as $path) {
            if ($disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
            }
        }
    }
}
