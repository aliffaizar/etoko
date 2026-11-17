<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BranchStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReportRequest;
use App\Models\Branch;
use App\Models\Category;
use App\Models\User;
use App\Services\Reporting\BestSellerReport;
use App\Services\Reporting\BranchPerformanceReport;
use App\Services\Reporting\CustomerReport;
use App\Services\Reporting\ReportCatalog;
use App\Services\Reporting\ReportFilters;
use App\Services\Reporting\SalesReport;
use App\Services\Reporting\StockCardReport;
use App\Services\Reporting\StockPositionReport;
use App\Services\Reporting\TransactionReport;
use App\Support\CategoryTree;
use Closure;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The report screens (P2-E7-T4..T8), for central and branch staff alike.
 *
 * One screen per report, narrowed rather than duplicated (ADR-016): every
 * table a report reads carries the branch global scope, so a branch admin's
 * reports are already their own branch's, and only central staff get a
 * branch selector (BR-3, `ReportFilters::fromInput()`).
 *
 * The figures arrive as one deferred prop, `report`, so the page and its
 * filters draw at once and the numbers fill in behind a skeleton. The
 * definitions behind them live in `App\Services\Reporting`; this controller
 * only chooses which report to build and which options the toolbar offers.
 */
class ReportController extends Controller
{
    public function sales(ReportRequest $request): Response
    {
        return $this->render($request, 'sales', function (ReportFilters $filters): array {
            $report = new SalesReport($filters);

            return [
                'summary' => $report->summary(),
                'series' => $report->series(),
                'categories' => $report->byCategory(),
            ];
        });
    }

    public function transactions(ReportRequest $request): Response
    {
        return $this->render($request, 'transactions', function (ReportFilters $filters): array {
            $report = new TransactionReport($filters);

            return [
                'summary' => $report->summary(),
                'methods' => $report->byMethod(),
                'orders' => $report->ordersByStatus(),
                'refunds' => $report->refunds(),
            ];
        });
    }

    public function stock(ReportRequest $request): Response
    {
        return $this->render($request, 'stock', function (ReportFilters $filters): array {
            $report = new StockPositionReport($filters);

            return [
                'summary' => $report->summary(),
                'rows' => $report->paginate(),
            ];
        });
    }

    public function stockCard(ReportRequest $request): Response
    {
        return $this->render($request, 'stock-card', function (ReportFilters $filters): array {
            $report = new StockCardReport($filters);

            return [
                'summary' => $report->summary(),
                'rows' => $report->paginate(),
            ];
        });
    }

    public function products(ReportRequest $request): Response
    {
        return $this->render($request, 'products', fn (ReportFilters $filters): array => [
            'rows' => (new BestSellerReport($filters))->paginate(),
        ]);
    }

    public function customers(ReportRequest $request): Response
    {
        return $this->render($request, 'customers', function (ReportFilters $filters): array {
            $report = new CustomerReport($filters);

            return [
                'summary' => $report->summary(),
                'rows' => $report->paginate(),
            ];
        });
    }

    public function branches(ReportRequest $request): Response
    {
        return $this->render($request, 'branches', fn (ReportFilters $filters): array => [
            'rows' => (new BranchPerformanceReport($filters))->rows(),
        ]);
    }

    /**
     * @param  Closure(ReportFilters): array<string, mixed>  $build
     */
    private function render(ReportRequest $request, string $key, Closure $build): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $filters = $request->reportFilters();

        return Inertia::render('admin/reports/'.$key, [
            'filters' => $filters->toArray(),
            'report' => Inertia::defer(fn (): array => $build($filters)),
            'options' => [
                // Branch accounts get no branch select: their own is the only
                // one the report can contain (BR-3).
                'branches' => $actor->isCentral() ? $this->branchOptions() : [],
                'categories' => $this->categoryOptions(),
            ],
            'can' => ['export' => ReportCatalog::mayExport($actor, $key)],
        ]);
    }

    /**
     * Every branch, inactive ones included: a closed branch still has a
     * history to report on.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function branchOptions(): array
    {
        return Branch::query()
            ->ordered()
            ->get(['id', 'name', 'status'])
            ->map(fn (Branch $branch): array => [
                'value' => (string) $branch->id,
                'label' => $branch->status === BranchStatus::Active
                    ? $branch->name
                    : "{$branch->name} ({$branch->status->label()})",
            ])
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function categoryOptions(): array
    {
        return array_map(
            fn (array $row): array => [
                'value' => (string) $row['category']->id,
                'label' => str_repeat('— ', $row['depth'] - 1).$row['category']->name,
            ],
            CategoryTree::of(Category::query()->get())->flatten(),
        );
    }
}
