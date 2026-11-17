<?php

namespace App\Services\Reporting;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethodType;
use App\Enums\PaymentTransactionStatus;
use App\Enums\RefundMethod;
use App\Enums\RefundStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Builder;

/**
 * Transactions over a period: payment attempts per method and status, orders
 * per status, and refunds per method and status (P2-E7-T5).
 *
 * This is the money view, not the sales view. A payment row is one **attempt**
 * (ADR-038), so a customer who let a virtual account lapse and paid by QRIS
 * shows up as one expired and one successful attempt — which is the question
 * this report exists to answer ("how often do VA payments lapse?"). Each
 * section is dated by when its own row was made: attempts and refunds by
 * `created_at`, orders by `placed_at`.
 *
 * `Payment`, `Order` and `Refund` all carry the branch global scope, so a
 * branch account reads its own branch only (BR-3).
 */
final class TransactionReport implements ExportableReport
{
    /** @var array<int, array{method_name: string, method_type: string, method_type_label: string, attempts: int, success_count: int, success_amount: int, pending_count: int, failed_count: int, refunded_count: int, success_rate: float}>|null */
    private ?array $methodRows = null;

    public function __construct(private readonly ReportFilters $filters) {}

    /**
     * One row per payment method, with its attempts split by outcome.
     *
     * Grouped by the method's snapshot name (ADR-007), so a renamed method
     * keeps its old name on old attempts.
     *
     * @return array<int, array{method_name: string, method_type: string, method_type_label: string, attempts: int, success_count: int, success_amount: int, pending_count: int, failed_count: int, refunded_count: int, success_rate: float}>
     */
    public function byMethod(): array
    {
        $success = PaymentTransactionStatus::Success->value;
        $pending = PaymentTransactionStatus::Pending->value;
        $refunded = PaymentTransactionStatus::Refunded->value;
        $failed = [PaymentTransactionStatus::Failed->value, PaymentTransactionStatus::Expired->value];

        return $this->methodRows ??= $this->payments()
            ->toBase()
            ->select('method_name', 'method_type')
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as success_count', [$success])
            ->selectRaw('SUM(CASE WHEN status = ? THEN amount ELSE 0 END) as success_amount', [$success])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_count', [$pending])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as failed_count', $failed)
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as refunded_count', [$refunded])
            ->groupBy('method_name', 'method_type')
            ->orderByDesc('success_amount')
            ->orderBy('method_name')
            ->get()
            ->map(function (object $row): array {
                $attempts = (int) $row->attempts;
                $type = PaymentMethodType::tryFrom((string) $row->method_type);

                return [
                    'method_name' => (string) $row->method_name,
                    'method_type' => (string) $row->method_type,
                    'method_type_label' => $type?->label() ?? (string) $row->method_type,
                    'attempts' => $attempts,
                    'success_count' => (int) $row->success_count,
                    'success_amount' => (int) $row->success_amount,
                    'pending_count' => (int) $row->pending_count,
                    'failed_count' => (int) $row->failed_count,
                    'refunded_count' => (int) $row->refunded_count,
                    'success_rate' => $attempts === 0 ? 0.0 : round((int) $row->success_count / $attempts, 4),
                ];
            })
            ->all();
    }

    /**
     * Orders placed in the period, per status, with every status listed so a
     * status with nothing in it reads as zero rather than being missing.
     *
     * @return array<int, array{status: string, label: string, count: int, amount: int}>
     */
    public function ordersByStatus(): array
    {
        $rows = Order::query()
            ->whereBetween('placed_at', [$this->filters->from, $this->filters->until])
            ->when($this->filters->branchId !== null, fn (Builder $query) => $query
                ->where('branch_id', $this->filters->branchId))
            ->toBase()
            ->select('status')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(grand_total), 0) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        return array_map(fn (OrderStatus $status): array => [
            'status' => $status->value,
            'label' => $status->label(),
            'count' => (int) ($rows[$status->value]->count ?? 0),
            'amount' => (int) ($rows[$status->value]->amount ?? 0),
        ], array_filter(
            OrderStatus::cases(),
            // Never written by any flow (ADR-048); listing it would suggest
            // it can happen.
            fn (OrderStatus $status): bool => $status !== OrderStatus::PartiallyRefunded,
        ));
    }

    /**
     * Refunds made in the period, per method and status.
     *
     * @return array<int, array{method: string, method_label: string, status: string, status_label: string, count: int, amount: int}>
     */
    public function refunds(): array
    {
        return Refund::query()
            ->whereBetween('created_at', [$this->filters->from, $this->filters->until])
            ->when($this->filters->branchId !== null, fn (Builder $query) => $query
                ->where('branch_id', $this->filters->branchId))
            ->toBase()
            ->select('method', 'status')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(amount), 0) as amount')
            ->groupBy('method', 'status')
            ->orderBy('method')
            ->orderBy('status')
            ->get()
            ->map(fn (object $row): array => [
                'method' => (string) $row->method,
                'method_label' => RefundMethod::tryFrom((string) $row->method)?->label() ?? (string) $row->method,
                'status' => (string) $row->status,
                'status_label' => RefundStatus::tryFrom((string) $row->status)?->label() ?? (string) $row->status,
                'count' => (int) $row->count,
                'amount' => (int) $row->amount,
            ])
            ->all();
    }

    /**
     * @return array{attempts: int, success_count: int, success_amount: int, failed_count: int, refunded_amount: int}
     */
    public function summary(): array
    {
        $methods = collect($this->byMethod());

        return [
            'attempts' => (int) $methods->sum('attempts'),
            'success_count' => (int) $methods->sum('success_count'),
            'success_amount' => (int) $methods->sum('success_amount'),
            'failed_count' => (int) $methods->sum('failed_count'),
            'refunded_amount' => (int) collect($this->refunds())
                ->where('status', RefundStatus::Processed->value)
                ->sum('amount'),
        ];
    }

    public function rowCount(): int
    {
        return count($this->byMethod());
    }

    public function table(): ReportTable
    {
        $methods = $this->byMethod();
        $summary = $this->summary();

        return new ReportTable(
            title: 'Laporan Transaksi',
            columns: [
                'method_name' => ['label' => 'Metode', 'type' => ReportTable::TEXT],
                'method_type_label' => ['label' => 'Jenis', 'type' => ReportTable::TEXT],
                'attempts' => ['label' => 'Percobaan', 'type' => ReportTable::NUMBER],
                'success_count' => ['label' => 'Berhasil', 'type' => ReportTable::NUMBER],
                'success_amount' => ['label' => 'Nominal berhasil', 'type' => ReportTable::MONEY],
                'pending_count' => ['label' => 'Menunggu', 'type' => ReportTable::NUMBER],
                'failed_count' => ['label' => 'Gagal/kedaluwarsa', 'type' => ReportTable::NUMBER],
                'refunded_count' => ['label' => 'Dikembalikan', 'type' => ReportTable::NUMBER],
                'success_rate' => ['label' => 'Tingkat berhasil', 'type' => ReportTable::PERCENT],
            ],
            rows: $methods,
            meta: $this->filters->describe(),
            totals: [
                'method_name' => 'Total',
                'method_type_label' => '',
                'attempts' => $summary['attempts'],
                'success_count' => $summary['success_count'],
                'success_amount' => $summary['success_amount'],
                'pending_count' => array_sum(array_column($methods, 'pending_count')),
                'failed_count' => $summary['failed_count'],
                'refunded_count' => array_sum(array_column($methods, 'refunded_count')),
                'success_rate' => $summary['attempts'] === 0
                    ? 0.0
                    : round($summary['success_count'] / $summary['attempts'], 4),
            ],
        );
    }

    /**
     * @return Builder<Payment>
     */
    private function payments(): Builder
    {
        return Payment::query()
            ->whereBetween('created_at', [$this->filters->from, $this->filters->until])
            ->when($this->filters->branchId !== null, fn (Builder $query) => $query
                ->where('branch_id', $this->filters->branchId));
    }
}
