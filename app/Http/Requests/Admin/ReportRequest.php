<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use App\Services\Reporting\BestSellerReport;
use App\Services\Reporting\CustomerReport;
use App\Services\Reporting\ReportExporter;
use App\Services\Reporting\ReportFilters;
use App\Services\Reporting\StockPositionReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The query string every report screen and export reads (P2-E7).
 *
 * One request for all of them, because they share one filter object: a
 * report and the file exported from it must be asked the same question.
 * Sorting is whitelisted against the union of the sortable reports' columns;
 * each report then ignores a sort it does not know, so a crafted `sort` never
 * reaches an `ORDER BY`.
 */
class ReportRequest extends FormRequest
{
    /**
     * Authorisation is the route's permission middleware (see
     * `ReportCatalog`); this request only shapes the input.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
            'category' => ['nullable', 'integer', 'exists:categories,id'],
            'group' => ['nullable', 'string', Rule::in(ReportFilters::GROUPS)],
            'search' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', Rule::in(StockPositionReport::STATES)],
            'sort' => ['nullable', 'string', Rule::in(array_unique([
                ...array_keys(StockPositionReport::SORTS),
                ...array_keys(BestSellerReport::SORTS),
                ...array_keys(CustomerReport::SORTS),
            ]))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', 'string', Rule::in(ReportExporter::FORMATS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => 'tanggal awal',
            'until' => 'tanggal akhir',
            'branch' => 'cabang',
            'category' => 'kategori',
        ];
    }

    /**
     * A report covers at most two years: beyond that a daily chart is
     * unreadable and the queries stop being cheap.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var User $actor */
                $actor = $this->user();
                $filters = ReportFilters::fromInput($validator->validated(), $actor);

                if ($filters->from->diffInDays($filters->until) >= ReportFilters::MAX_DAYS) {
                    $validator->errors()->add('until', 'Periode laporan paling panjang dua tahun.');
                }
            },
        ];
    }

    public function reportFilters(): ReportFilters
    {
        /** @var User $actor */
        $actor = $this->user();

        return ReportFilters::fromInput($this->safe()->all(), $actor);
    }
}
