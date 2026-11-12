<?php

namespace App\Services\Payment\Midtrans;

use App\Exceptions\MidtransApiException;
use Closure;
use Exception;
use Midtrans\Config;
use Midtrans\CoreApi;
use Midtrans\Transaction;

/**
 * The three Midtrans calls the application makes, through the official SDK
 * (`midtrans/midtrans-php`, ADR-053).
 *
 * The SDK is a set of static methods over cURL with its configuration in
 * static properties, which is two things a driver should not have to know
 * about and a test cannot fake. This class is the one place that touches it:
 * it sets the configuration immediately before every call, turns the SDK's
 * `stdClass` answers into arrays, and turns its bare `\Exception` into a
 * `MidtransApiException` that says whether Midtrans refused or never answered.
 *
 * Deliberately not `final`: it is resolved from the container so the tests
 * can bind `Tests\Fakes\FakeMidtransClient` in its place. No test reaches the
 * real Midtrans, and none needs sandbox credentials.
 */
class MidtransClient
{
    /**
     * Core API `POST /v2/charge`.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws MidtransApiException
     */
    public function charge(array $params): array
    {
        return $this->call(static fn (): mixed => CoreApi::charge($params));
    }

    /**
     * Core API `GET /v2/{order_id}/status`.
     *
     * @return array<string, mixed>
     *
     * @throws MidtransApiException
     */
    public function status(string $reference): array
    {
        return $this->call(static fn (): mixed => Transaction::status($reference));
    }

    /**
     * Core API `POST /v2/{order_id}/refund`.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws MidtransApiException
     */
    public function refund(string $reference, array $params): array
    {
        return $this->call(static fn (): mixed => Transaction::refund($reference, $params));
    }

    /**
     * @param  Closure(): mixed  $request
     * @return array<string, mixed>
     *
     * @throws MidtransApiException
     */
    private function call(Closure $request): array
    {
        $this->configure();

        try {
            $answer = $request();
        } catch (Exception $failure) {
            throw $this->translate($failure);
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode((string) json_encode($answer), associative: true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Point the SDK's static configuration at this application's settings.
     *
     * Done before every call rather than once at boot, because the properties
     * are process-wide: a queue worker lives for hours, and whatever was set
     * last is what the next call would otherwise use.
     *
     * The notification overrides are cleared on purpose. The application has
     * no webhook, and a URL left in either would have Midtrans calling one.
     */
    private function configure(): void
    {
        $timeout = (int) config('services.midtrans.timeout', 15);

        Config::$serverKey = (string) config('services.midtrans.server_key');
        Config::$clientKey = (string) config('services.midtrans.client_key');
        Config::$isProduction = (bool) config('services.midtrans.production');
        Config::$isSanitized = false;
        Config::$appendNotifUrl = null;
        Config::$overrideNotifUrl = null;
        Config::$paymentIdempotencyKey = null;
        // The empty header list is required: the SDK reads that key from
        // these options without checking it is there.
        Config::$curlOptions = [
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => [],
        ];
    }

    /**
     * Read the SDK's exception for what it actually was.
     *
     * The SDK reports an API error with the status as the exception's code and
     * the response body pasted into its message, and a transport failure as a
     * "CURL Error" whose code is cURL's own — so the two are told apart by the
     * message it wrote, which is the only thing that distinguishes them.
     */
    private function translate(Exception $failure): MidtransApiException
    {
        $message = $failure->getMessage();

        if (! str_contains($message, 'HTTP status code')) {
            return MidtransApiException::unreachable($failure);
        }

        $body = [];
        $marker = 'API response: ';
        $position = strpos($message, $marker);

        if ($position !== false) {
            $decoded = json_decode(substr($message, $position + strlen($marker)), associative: true);
            $body = is_array($decoded) ? $decoded : [];
        }

        return MidtransApiException::refused((int) $failure->getCode(), $body, $failure);
    }
}
