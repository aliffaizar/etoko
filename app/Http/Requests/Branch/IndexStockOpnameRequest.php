<?php

namespace App\Http\Requests\Branch;

use App\Enums\StockOpnameStatus;
use Illuminate\Validation\Rule;

/**
 * The stock-count listing's query string (P1-E4-T7).
 *
 * Identical to the goods documents' except for the status values: a count can
 * be "sedang dihitung", which no goods document can be. Overriding the rule is
 * what keeps the shared request from having to accept, on every screen, a
 * state only one screen can be in.
 */
class IndexStockOpnameRequest extends IndexStockDocumentRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'status' => ['nullable', 'string', Rule::enum(StockOpnameStatus::class)],
            // A count has no reason column; dropping the rule keeps a stray
            // `?reason=` from looking meaningful on this screen.
            'reason' => ['prohibited'],
        ];
    }
}
