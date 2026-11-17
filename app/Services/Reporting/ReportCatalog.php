<?php

namespace App\Services\Reporting;

use App\Models\User;

/**
 * Every report the panel offers, with the permission that guards it
 * (P2-E7, docs/04-rbac.md).
 *
 * One list for the routes, the export endpoint and the queued export job, so
 * a report cannot be exported by somebody who may not open it, and the job
 * rebuilds exactly the report it was asked for. Exporting needs
 * `reports.export` **and** the report's own permission.
 */
final class ReportCatalog
{
    /**
     * @var array<string, array{title: string, permission: string, report: class-string<ExportableReport>}>
     */
    public const REPORTS = [
        'sales' => ['title' => 'Penjualan', 'permission' => 'reports.sales.view', 'report' => SalesReport::class],
        'transactions' => ['title' => 'Transaksi', 'permission' => 'reports.sales.view', 'report' => TransactionReport::class],
        'stock' => ['title' => 'Posisi Stok', 'permission' => 'reports.stock.view', 'report' => StockPositionReport::class],
        'stock-card' => ['title' => 'Kartu Stok', 'permission' => 'reports.stock.view', 'report' => StockCardReport::class],
        'products' => ['title' => 'Produk Terlaris', 'permission' => 'reports.product.view', 'report' => BestSellerReport::class],
        'customers' => ['title' => 'Pelanggan', 'permission' => 'reports.customer.view', 'report' => CustomerReport::class],
        'branches' => ['title' => 'Performa Cabang', 'permission' => 'reports.branch.view', 'report' => BranchPerformanceReport::class],
    ];

    public const EXPORT_PERMISSION = 'reports.export';

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_keys(self::REPORTS);
    }

    public static function make(string $key, ReportFilters $filters): ExportableReport
    {
        $class = self::REPORTS[$key]['report'];

        return new $class($filters);
    }

    public static function mayView(User $user, string $key): bool
    {
        return isset(self::REPORTS[$key]) && $user->can(self::REPORTS[$key]['permission']);
    }

    public static function mayExport(User $user, string $key): bool
    {
        return self::mayView($user, $key) && $user->can(self::EXPORT_PERMISSION);
    }
}
