<?php

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The invoice an order is given when it is paid for (P2-E6-T1).
 *
 * Written only by `App\Actions\Order\GenerateInvoice`, which is idempotent:
 * asking twice for the same order returns the invoice it already has. Never
 * edited afterwards — the number, the date and the total are what the
 * customer was given.
 *
 * Not branch-scoped and not `Auditable`: it is reached only through its order,
 * which carries both, and it never changes after it is written.
 *
 * @property int $id
 * @property int $order_id
 * @property string $invoice_number
 * @property Carbon $issued_at
 * @property int $total
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Order $order
 */
#[Fillable(['order_id', 'invoice_number', 'issued_at', 'total'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'total' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The file name a download is offered under.
     */
    public function fileName(): string
    {
        return "{$this->invoice_number}.pdf";
    }
}
