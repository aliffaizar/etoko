<?php

use App\Exceptions\MidtransApiException;
use App\Services\Payment\Midtrans\MidtransClient;
use Midtrans\MT_Tests;

require_once __DIR__.'/../../../Fakes/MidtransSdkStub.php';

/*
 * The wrapper around the official Midtrans SDK (ADR-053).
 *
 * The one test that runs the real SDK code, stopped just short of the network
 * by the SDK's own hook. What is being checked is the translation this class
 * exists for: our configuration into the SDK's static properties, its
 * `stdClass` answers into arrays, and its bare exceptions into the two cases
 * the driver tells apart.
 */

beforeEach(function () {
    config()->set('services.midtrans.server_key', 'SB-Mid-server-CLIENT');
    config()->set('services.midtrans.production', false);

    MT_Tests::$stubHttp = true;
    MT_Tests::$lastHttpRequest = [];

    // The global fake is bound for every test; this one wants the real thing.
    $this->client = new MidtransClient;
});

afterEach(function () {
    MT_Tests::$stubHttp = false;
});

it('calls the sandbox with the configured server key, and answers in arrays', function () {
    MT_Tests::$stubHttpResponse = json_encode([
        'status_code' => '201',
        'transaction_id' => 'mt-1',
        'va_numbers' => [['bank' => 'bca', 'va_number' => '812']],
    ]);

    $answer = $this->client->charge(['payment_type' => 'bank_transfer']);

    expect($answer['va_numbers'][0]['va_number'])->toBe('812')
        ->and(MT_Tests::$lastHttpRequest['url'])->toBe('https://api.sandbox.midtrans.com/v2/charge')
        ->and(MT_Tests::$lastHttpRequest['server_key'])->toBe('SB-Mid-server-CLIENT');
});

it('calls production when the shop is configured for it', function () {
    config()->set('services.midtrans.production', true);
    MT_Tests::$stubHttpResponse = json_encode(['status_code' => '200']);

    $this->client->status('ORD-1-AAAA');

    expect(MT_Tests::$lastHttpRequest['url'])->toBe('https://api.midtrans.com/v2/ORD-1-AAAA/status');
});

it('reports an API error with its status and what Midtrans said', function () {
    MT_Tests::$stubHttpResponse = json_encode([
        'status_code' => '404',
        'status_message' => "Transaction doesn't exist.",
    ]);

    try {
        $this->client->status('ORD-1-AAAA');
        $this->fail('Expected a MidtransApiException.');
    } catch (MidtransApiException $refused) {
        expect($refused->isNotFound())->toBeTrue()
            ->and($refused->isUnreachable())->toBeFalse()
            ->and($refused->body['status_message'])->toBe("Transaction doesn't exist.");
    }
});

it('reports a connection that never answered as unreachable, not as a refusal', function () {
    MT_Tests::$stubHttpResponse = false;

    try {
        $this->client->refund('ORD-1-AAAA', ['amount' => 1_000]);
        $this->fail('Expected a MidtransApiException.');
    } catch (MidtransApiException $unreachable) {
        expect($unreachable->isUnreachable())->toBeTrue();
    }
});
