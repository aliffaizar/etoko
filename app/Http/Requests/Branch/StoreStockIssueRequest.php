<?php

namespace App\Http\Requests\Branch;

use App\Enums\StockIssueReason;
use App\Support\StockDocumentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A goods-out document being drafted or edited (P1-E4-T6).
 *
 * The reason is required, because "four units left" is not something anybody
 * can act on. "Lainnya" additionally requires notes: it is a reason that
 * explains nothing by itself, so the form asks for the explanation instead of
 * accepting the shrug.
 *
 * Availability is **not** validated here. Between this form and the posting the
 * figure can move either way, so the only honest place to ask "is there
 * enough?" is inside `StockLedger`, while it holds the row lock (BR-6).
 */
class StoreStockIssueRequest extends FormRequest
{
    /**
     * The route carries the permission middleware and the controller asks
     * `StockIssuePolicy`.
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
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'reason' => ['required', 'string', Rule::enum(StockIssueReason::class)],
            'reference_no' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
            ...StockDocumentRules::items(),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->requireNotesForVagueReason($validator)];
    }

    private function requireNotesForVagueReason(Validator $validator): void
    {
        if ($validator->errors()->has('reason')) {
            return;
        }

        $reason = StockIssueReason::tryFrom((string) $this->string('reason'));

        if ($reason === null || ! $reason->requiresNotes()) {
            return;
        }

        if (trim((string) $this->string('notes')) !== '') {
            return;
        }

        $validator->errors()->add(
            'notes',
            'Alasan "Lainnya" perlu penjelasan. Tulis apa yang terjadi di kolom catatan.',
        );
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'warehouse_id' => 'gudang',
            'reason' => 'alasan',
            'reference_no' => 'nomor referensi',
            'issue_date' => 'tanggal keluar',
            'notes' => 'catatan',
            ...StockDocumentRules::itemAttributes(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'issue_date.before_or_equal' => 'Tanggal keluar tidak boleh di masa depan.',
            ...StockDocumentRules::itemMessages(),
        ];
    }
}
