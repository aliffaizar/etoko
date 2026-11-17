<?php

namespace App\Services\Reporting;

/**
 * A report that can be written to a file (P2-E7-T9).
 */
interface ExportableReport
{
    /**
     * The report as a table, built from the same filters as its screen.
     */
    public function table(): ReportTable;

    /**
     * How many rows the table will hold, so the export can decide whether to
     * run now or on the queue before building anything.
     */
    public function rowCount(): int;
}
