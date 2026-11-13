@php
    use App\Enums\PaymentStatus;
    use App\Support\Money;

    /** @var \App\Models\Invoice $invoice */
    /** @var \App\Models\Order $order */
    /** @var \App\Models\Payment|null $payment */

    $timezone = config('app.timezone');
    $branch = $order->branch;
    $isSettled = $order->payment_status === PaymentStatus::Paid;
    $feeAmount = $payment?->fee_amount ?? 0;
    $stampLabel = match (true) {
        $isSettled => 'Lunas',
        $payment !== null && $payment->method_type->isCollectedOnDelivery() && $order->payment_status === PaymentStatus::Pending => 'Bayar di tempat',
        default => $order->payment_status->label(),
    };
    $branchAddress = collect([$branch->address_line, $branch->district_name, $branch->city_name, $branch->province_name, $branch->postal_code])
        ->filter()
        ->implode(', ');
    $shippingAddress = collect([$order->shipping_address_line, $order->shipping_district_name, $order->shipping_city_name, $order->shipping_province_name, $order->shipping_postal_code])
        ->filter()
        ->implode(', ');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 32px 36px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2937; line-height: 1.45; }
        h1 { font-size: 22px; margin: 0; letter-spacing: 1px; }
        h2 { font-size: 11px; margin: 0 0 4px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .muted { color: #6b7280; }
        .right { text-align: right; }
        .header td { padding-bottom: 18px; }
        .store-name { font-size: 15px; font-weight: bold; }
        .meta td { padding: 1px 0; }
        .parties td { width: 50%; padding: 12px 0 16px; }
        .items th { background: #f3f4f6; text-align: left; padding: 7px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: 1px solid #d1d5db; }
        .items td { padding: 7px 8px; border-bottom: 1px solid #e5e7eb; }
        .items .num { text-align: right; white-space: nowrap; }
        .totals { width: 46%; margin-left: 54%; margin-top: 12px; }
        .totals td { padding: 3px 8px; }
        .totals .grand td { border-top: 1px solid #1f2937; font-size: 12px; font-weight: bold; padding-top: 7px; }
        .stamp { display: inline-block; padding: 4px 10px; border: 2px solid; font-weight: bold; font-size: 11px; letter-spacing: 1px; text-transform: uppercase; }
        .stamp-paid { color: #047857; border-color: #047857; }
        .stamp-open { color: #b45309; border-color: #b45309; }
        .footer { margin-top: 28px; padding-top: 10px; border-top: 1px solid #e5e7eb; font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                <div class="store-name">{{ $storeName }}</div>
                <div>{{ $branch->name }}</div>
                <div class="muted">{{ $branchAddress }}</div>
                @if ($branch->phone || $branch->email)
                    <div class="muted">{{ collect([$branch->phone, $branch->email])->filter()->implode(' · ') }}</div>
                @endif
            </td>
            <td class="right">
                <h1>INVOICE</h1>
                <table class="meta" style="width: auto; margin-left: auto; margin-top: 6px;">
                    <tr><td class="muted" style="padding-right: 10px;">Nomor</td><td class="right"><strong>{{ $invoice->invoice_number }}</strong></td></tr>
                    <tr><td class="muted" style="padding-right: 10px;">Tanggal</td><td class="right">{{ $invoice->issued_at->timezone($timezone)->translatedFormat('j F Y') }}</td></tr>
                    <tr><td class="muted" style="padding-right: 10px;">Pesanan</td><td class="right">{{ $order->order_number }}</td></tr>
                    @if ($order->placed_at)
                        <tr><td class="muted" style="padding-right: 10px;">Tanggal pesan</td><td class="right">{{ $order->placed_at->timezone($timezone)->translatedFormat('j F Y') }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td>
                <h2>Ditagihkan kepada</h2>
                <div><strong>{{ $order->customer_name }}</strong></div>
                @if ($order->customer_email)
                    <div class="muted">{{ $order->customer_email }}</div>
                @endif
                @if ($order->customer_phone)
                    <div class="muted">{{ $order->customer_phone }}</div>
                @endif
            </td>
            <td>
                @if ($order->isPickup())
                    <h2>Pengambilan</h2>
                    <div>Diambil di {{ $branch->name }}</div>
                @elseif ($shippingAddress !== '')
                    <h2>Dikirim ke</h2>
                    <div><strong>{{ $order->shipping_recipient }}</strong></div>
                    @if ($order->shipping_phone)
                        <div class="muted">{{ $order->shipping_phone }}</div>
                    @endif
                    <div class="muted">{{ $shippingAddress }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%;">#</th>
                <th>Produk</th>
                <th class="num" style="width: 8%;">Jumlah</th>
                <th class="num" style="width: 17%;">Harga</th>
                <th class="num" style="width: 17%;">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td class="muted">{{ $loop->iteration }}</td>
                    <td>
                        <div>{{ $item->product_name }}</div>
                        <div class="muted">
                            {{ collect([$item->variant_name, 'SKU '.$item->sku])->filter()->implode(' · ') }}
                        </div>
                        @if ($item->discount_amount > 0)
                            <div class="muted">Potongan {{ Money::format($item->discount_amount) }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $item->quantity }}</td>
                    <td class="num">{{ Money::format($item->unit_price) }}</td>
                    <td class="num">{{ Money::format($item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td class="muted">Subtotal</td><td class="right">{{ Money::format($order->subtotal) }}</td></tr>
        @if ($order->discount_total > 0)
            <tr>
                <td class="muted">Diskon{{ $order->voucher_code ? ' ('.$order->voucher_code.')' : '' }}</td>
                <td class="right">−{{ Money::format($order->discount_total) }}</td>
            </tr>
        @endif
        @if ($order->requiresShipping())
            <tr><td class="muted">Ongkos kirim</td><td class="right">{{ Money::format($order->shipping_cost) }}</td></tr>
        @endif
        @if ($order->tax_total > 0)
            <tr><td class="muted">Pajak</td><td class="right">{{ Money::format($order->tax_total) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="right">{{ Money::format($invoice->total) }}</td></tr>
        @if ($feeAmount > 0)
            <tr><td class="muted">Biaya metode pembayaran</td><td class="right">{{ Money::format($feeAmount) }}</td></tr>
            <tr><td class="muted">Total dibayar</td><td class="right">{{ Money::format($invoice->total + $feeAmount) }}</td></tr>
        @endif
    </table>

    <table style="margin-top: 18px;">
        <tr>
            <td>
                @if ($payment)
                    <h2>Pembayaran</h2>
                    <div>{{ $payment->method_name }}</div>
                    @if ($payment->paid_at)
                        <div class="muted">Dibayar {{ $payment->paid_at->timezone($timezone)->translatedFormat('j F Y, H:i') }} WIB</div>
                    @endif
                @endif
            </td>
            <td class="right">
                <span class="stamp {{ $isSettled ? 'stamp-paid' : 'stamp-open' }}">
                    {{ $stampLabel }}
                </span>
            </td>
        </tr>
    </table>

    <div class="footer">
        Invoice ini dibuat otomatis oleh sistem {{ $storeName }} dan sah tanpa tanda tangan.
        Simpan dokumen ini sebagai bukti transaksi.
    </div>
</body>
</html>
