<?php

use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

it('formats the first number of the day with the prefix and date', function () {
    $this->travelTo('2026-11-02 08:00:00');

    expect(DocumentNumber::next(DocumentNumber::PREFIX_ORDER))->toBe('ORD-20261102-0001');
});

it('increments the sequence for repeated calls on the same day', function () {
    $this->travelTo('2026-11-02 08:00:00');

    $numbers = [
        DocumentNumber::next(DocumentNumber::PREFIX_ORDER),
        DocumentNumber::next(DocumentNumber::PREFIX_ORDER),
        DocumentNumber::next(DocumentNumber::PREFIX_ORDER),
    ];

    expect($numbers)->toBe(['ORD-20261102-0001', 'ORD-20261102-0002', 'ORD-20261102-0003']);
});

it('keeps a separate sequence per prefix', function () {
    $this->travelTo('2026-11-02 08:00:00');

    DocumentNumber::next(DocumentNumber::PREFIX_ORDER);

    expect(DocumentNumber::next(DocumentNumber::PREFIX_INVOICE))->toBe('INV-20261102-0001');
});

it('restarts the sequence on the next day', function () {
    $this->travelTo('2026-11-02 23:59:00');
    DocumentNumber::next(DocumentNumber::PREFIX_STOCK_TRANSFER);

    $this->travelTo('2026-11-03 00:01:00');

    expect(DocumentNumber::next(DocumentNumber::PREFIX_STOCK_TRANSFER))->toBe('TRF-20261103-0001');
});

it('stores one counter row per prefix and day', function () {
    $this->travelTo('2026-11-02 08:00:00');

    DocumentNumber::next(DocumentNumber::PREFIX_ORDER);
    DocumentNumber::next(DocumentNumber::PREFIX_ORDER);

    expect(DB::table('document_counters')->where('prefix', 'ORD')->count())->toBe(1)
        ->and(DB::table('document_counters')->where('prefix', 'ORD')->value('last_number'))->toBe(2);
});
