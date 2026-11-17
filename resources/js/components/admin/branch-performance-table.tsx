import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { StatusBadge } from '@/components/panel/status-badge';
import { formatNumber, formatPercent, formatRupiah } from '@/lib/format';
import type { BranchPerformanceRow } from '@/types';

/**
 * Sales per branch, shared by the central dashboard and the branch
 * performance report (P2-E7-T2, P2-E7-T8).
 */
export function BranchPerformanceTable({
    rows,
    detailed = false,
}: {
    rows: BranchPerformanceRow[];
    /** Adds units, returns and cancellations — the report's extra columns. */
    detailed?: boolean;
}) {
    if (rows.length === 0) {
        return (
            <p className="py-6 text-center text-sm text-muted-foreground">
                Belum ada cabang.
            </p>
        );
    }

    return (
        <div className="overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Cabang</TableHead>
                        <TableHead className="text-right">Pesanan</TableHead>
                        {detailed && (
                            <TableHead className="text-right">Unit</TableHead>
                        )}
                        <TableHead className="text-right">
                            Penjualan bersih
                        </TableHead>
                        <TableHead className="text-right">
                            Rata-rata pesanan
                        </TableHead>
                        {detailed && (
                            <TableHead className="text-right">Retur</TableHead>
                        )}
                        {detailed && (
                            <TableHead className="text-right">
                                Dibatalkan
                            </TableHead>
                        )}
                        <TableHead className="text-right">Porsi</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row) => (
                        <TableRow key={row.branch_id}>
                            <TableCell>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {row.branch_name}
                                    </span>
                                    {!row.is_active && (
                                        <StatusBadge
                                            label="Nonaktif"
                                            tone="neutral"
                                        />
                                    )}
                                </div>
                                <span className="font-mono text-xs text-muted-foreground">
                                    {row.branch_code}
                                </span>
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatNumber(row.orders)}
                            </TableCell>
                            {detailed && (
                                <TableCell className="text-right tabular-nums">
                                    {formatNumber(row.units)}
                                </TableCell>
                            )}
                            <TableCell className="text-right whitespace-nowrap tabular-nums">
                                {formatRupiah(row.net)}
                            </TableCell>
                            <TableCell className="text-right whitespace-nowrap tabular-nums">
                                {formatRupiah(row.average_order)}
                            </TableCell>
                            {detailed && (
                                <TableCell className="text-right whitespace-nowrap tabular-nums">
                                    {formatRupiah(row.returned)}
                                </TableCell>
                            )}
                            {detailed && (
                                <TableCell className="text-right tabular-nums">
                                    {formatNumber(row.cancelled)}
                                </TableCell>
                            )}
                            <TableCell className="text-right tabular-nums">
                                {formatPercent(row.share * 100, 1)}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
