<?php

use App\Enums\UserRole;
use App\Jobs\GenerateReportExport;
use App\Models\Branch;
use App\Models\User;
use App\Notifications\ReportExportReady;
use App\Services\Branch\BranchContext;
use App\Services\Reporting\ReportExporter;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Spatie\Permission\Models\Role;

it('downloads a small report as an Excel workbook holding its figures', function () {
    reportSale(Branch::factory()->create(), '2026-11-02 10:00:00', [[25_000, 2]]);

    $response = $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.reports.export', [
            'report' => 'sales', 'format' => 'xlsx',
            'from' => '2026-11-01', 'until' => '2026-11-30', 'group' => 'month',
        ]));

    $response->assertOk()->assertDownload();
    expect($response->headers->get('content-disposition'))->toContain('laporan-penjualan-');

    $reader = new Reader;
    $reader->open($response->getFile()->getPathname());
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }
    $reader->close();

    expect($rows[0][0])->toBe('Laporan Penjualan')
        ->and($rows)->toContain(['November 2026', 1, 2, 50_000, 0, 50_000])
        ->and(end($rows))->toBe(['Total', 1, 2, 50_000, 0, 50_000]);
});

it('downloads a report as a PDF', function () {
    $response = $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.reports.export', ['report' => 'branches', 'format' => 'pdf']));

    $response->assertOk()->assertDownload();
    expect(file_get_contents($response->getFile()->getPathname()))->toStartWith('%PDF');
});

it('queues an export that is larger than the format allows in one request', function () {
    Queue::fake([GenerateReportExport::class]);
    $user = User::factory()->adminPusat()->create();

    // A daily sales report over two years has 700+ rows, above the PDF bar.
    $this->actingAs($user)
        ->from(route('admin.reports.sales'))
        ->get(route('admin.reports.export', [
            'report' => 'sales', 'format' => 'pdf', 'from' => '2025-01-01', 'until' => '2026-11-01',
        ]))
        ->assertRedirect(route('admin.reports.sales'));

    Queue::assertPushed(GenerateReportExport::class, fn (GenerateReportExport $job): bool => $job->userId === $user->id
        && $job->report === 'sales'
        && $job->format === 'pdf'
        && $job->filters['from'] === '2025-01-01');
});

it('refuses to export without the export permission', function () {
    $this->actingAs(User::factory()->staffGudang(Branch::factory()->create())->create())
        ->get(route('admin.reports.export', ['report' => 'stock']))
        ->assertForbidden();
});

it('refuses to export a report the actor may not open', function () {
    // A role that may export, but not read customers.
    Role::findByName(UserRole::AdminCabang->value)->revokePermissionTo('reports.customer.view');
    $user = User::factory()->adminCabang(Branch::factory()->create())->create();

    $this->actingAs($user)
        ->get(route('admin.reports.export', ['report' => 'customers']))
        ->assertForbidden();
});

it('builds a queued export inside the owner branch and tells them where it is', function () {
    Storage::fake('local');
    Notification::fake();
    $mine = Branch::factory()->create();
    $theirs = Branch::factory()->create();
    reportSale($mine, '2026-11-02 10:00:00', [[10_000, 1]]);
    reportSale($theirs, '2026-11-02 10:00:00', [[90_000, 1]]);
    $owner = User::factory()->adminCabang($mine)->create();

    (new GenerateReportExport($owner->id, 'branches', ['from' => '2026-11-01', 'until' => '2026-11-30'], 'xlsx'))
        ->handle(app(BranchContext::class), app(ReportExporter::class));

    $file = null;
    Notification::assertSentTo($owner, ReportExportReady::class, function (ReportExportReady $notification) use ($owner, &$file): bool {
        $file = $notification->toDatabase($owner)['file'];

        return true;
    });

    $path = GenerateReportExport::pathFor($owner, $file);
    Storage::disk('local')->assertExists($path);

    $reader = new Reader;
    $reader->open(Storage::disk('local')->path($path));
    $cells = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $cells = [...$cells, ...$row->toArray()];
        }
    }
    $reader->close();

    expect($cells)->toContain($mine->name)->not->toContain($theirs->name);
});

it('lets only the owner download a queued export', function () {
    Storage::fake('local');
    $owner = User::factory()->adminPusat()->create();
    $file = 'abcdefghijkl-laporan-penjualan-2026-11-17-101010.xlsx';
    Storage::disk('local')->put(GenerateReportExport::pathFor($owner, $file), 'xlsx-bytes');

    $this->actingAs($owner)
        ->get(route('admin.reports.exports.download', $file))
        ->assertOk()
        ->assertDownload('laporan-penjualan-2026-11-17-101010.xlsx');

    $this->actingAs(User::factory()->adminPusat()->create())
        ->get(route('admin.reports.exports.download', $file))
        ->assertNotFound();
});

it('sends the browser itself to the file when a report notification is opened', function () {
    $owner = User::factory()->adminPusat()->create();
    $owner->notify(new ReportExportReady('Laporan Penjualan', 'xlsx', 'abcdefghijkl-laporan.xlsx'));
    $notification = $owner->notifications()->sole();

    $this->actingAs($owner)
        ->post(route('branch.notifications.open', $notification->id), [], ['X-Inertia' => 'true'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('admin.reports.exports.download', 'abcdefghijkl-laporan.xlsx'));
});
