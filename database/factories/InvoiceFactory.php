<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\Order;
use App\Support\DocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * An invoice for a paid order.
     *
     * `total` is read from the order through a closure over `$attributes`, so
     * a caller who passes only `order_id` gets an invoice that agrees with
     * it. `GenerateInvoice` is what the application uses; this is for tests
     * that need an invoice to already exist.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory()->paid(),
            'invoice_number' => fn (): string => DocumentNumber::next(DocumentNumber::PREFIX_INVOICE),
            'issued_at' => now(),
            'total' => fn (array $attributes): int => Order::withoutGlobalScopes()
                ->whereKey($attributes['order_id'])
                ->sole()
                ->grand_total,
        ];
    }
}
