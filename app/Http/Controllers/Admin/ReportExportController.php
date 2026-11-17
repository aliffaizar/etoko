<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportRequest;
use App\Jobs\GenerateReportExport;
use App\Models\User;
use App\Services\Reporting\ReportCatalog;
use App\Services\Reporting\ReportExporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporting a report to Excel or PDF (P2-E7-T9).
 *
 * A small report is written and downloaded in the same request. A large one
 * — more rows than the format's threshold — goes to the queue
 * (`GenerateReportExport`), and the person is told the file will arrive in
 * their notifications; holding a request open while dompdf lays out ten
 * thousand rows would time out long before it finished.
 *
 * The export link is a plain browser download, not an Inertia visit, so the
 * immediate answer is a file; the queued answer is a redirect back with a
 * toast.
 */
class ReportExportController extends Controller
{
    /**
     * Rows above which an export is queued, per format. PDF layout is far
     * slower than writing a spreadsheet, hence the lower bar.
     *
     * @var array<string, int>
     */
    public const QUEUE_THRESHOLD = [
        ReportExporter::XLSX => 2_000,
        ReportExporter::PDF => 300,
    ];

    public function export(ReportRequest $request, string $report, ReportExporter $exporter): BinaryFileResponse|RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        abort_unless(ReportCatalog::mayExport($actor, $report), 403);

        $filters = $request->reportFilters();
        $format = (string) $request->validated('format', ReportExporter::XLSX);
        $built = ReportCatalog::make($report, $filters);

        if ($built->rowCount() > self::QUEUE_THRESHOLD[$format]) {
            GenerateReportExport::dispatch($actor->id, $report, $filters->toQuery(), $format);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => 'Laporan besar sedang disiapkan. Tautan unduhannya akan muncul di notifikasi.',
            ]);

            return back();
        }

        $table = $built->table();
        $path = $exporter->writeTemporary($table, $format);

        return response()
            ->download($path, $exporter->fileName($table, $format))
            ->deleteFileAfterSend();
    }

    /**
     * A queued export, downloaded by the person who asked for it.
     *
     * The file is looked for in the actor's own folder only, so another
     * person's file name in the URL is simply not found.
     */
    public function download(string $file): StreamedResponse
    {
        /** @var User $actor */
        $actor = request()->user();
        $path = GenerateReportExport::pathFor($actor, $file);
        $disk = Storage::disk('local');

        abort_unless($disk->exists($path), 404, 'File export ini sudah tidak tersedia. Silakan export ulang.');

        // The stored name carries a random prefix that keeps it unguessable;
        // the person gets the readable part.
        return $disk->download($path, preg_replace('/^[a-z0-9]{12}-/', '', $file));
    }
}
