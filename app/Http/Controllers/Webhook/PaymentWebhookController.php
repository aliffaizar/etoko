<?php

namespace App\Http\Controllers\Webhook;

use App\Actions\Payment\HandlePaymentCallback;
use App\Enums\PaymentDriver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where payment providers tell us what happened (P2-E1-T6).
 *
 * The one endpoint in the application with **no authentication and no CSRF
 * token**, because the caller is somebody else's server. What stands in for
 * both is the driver's signature check, run before a single row is read:
 * `MidtransGateway::handleCallback()` recomputes
 * `sha512(order_id + status_code + gross_amount + server_key)` and refuses
 * anything that does not match. Without that, this route would be a button
 * anybody could press to mark their own order paid.
 *
 * Everything else about a callback is `HandlePaymentCallback`'s, deliberately:
 * the controller's whole job is to turn a result into a status code, and the
 * status code is the only thing a gateway reads.
 *
 * **The status codes are chosen for a retry loop, not for a reader.** Gateways
 * resend anything they are not answered with a 2xx:
 *
 * - **200** — acted on, or recognised and deliberately ignored (a duplicate, a
 *   status that changes nothing). Both mean "stop sending this".
 * - **400** — the payload is not something we can ever act on: a bad
 *   signature, an unknown reference, the wrong driver, a mismatched amount.
 *   Retrying will not change any of those, and a gateway that keeps trying for
 *   hours buries the one callback that mattered.
 *
 * Nothing here answers 500 for a payload problem, and nothing throws: an
 * exception would become a 500, and a 500 is an invitation to retry.
 *
 * This answers JSON rather than a redirect, which is the one legitimate
 * exception to the project's Inertia rule (`BUG-09`): the caller is a machine
 * and has no page to go back to.
 */
class PaymentWebhookController extends Controller
{
    /**
     * Take a notification from `$driver`.
     *
     * `$driver` comes from the URL and is validated by the route's `whereIn`,
     * so an unknown value is a 404 and never reaches the enum. It only selects
     * which signature to verify against — it is never trusted to say which
     * payment the callback is about, and `HandlePaymentCallback` refuses a
     * notification whose driver does not match the payment it names.
     */
    public function __invoke(Request $request, string $driver, HandlePaymentCallback $handle): JsonResponse
    {
        $resolved = PaymentDriver::tryFrom($driver);

        if (! $resolved instanceof PaymentDriver) {
            return new JsonResponse(['message' => 'Penyedia pembayaran tidak dikenal.'], 404);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        Log::info('Received a payment callback.', [
            'driver' => $resolved->value,
            'ip' => $request->ip(),
            // The reference only, not the payload: a log line is not the place
            // for a gateway's signature, and the payload itself is kept on the
            // payment row where a dispute can find it.
            'reference' => is_string($payload['order_id'] ?? null) ? $payload['order_id'] : null,
        ]);

        $result = $handle->handle($resolved, $payload);

        if (! $result->accepted) {
            return new JsonResponse(
                ['message' => $result->reason ?? 'Callback tidak dapat diproses.'],
                400,
            );
        }

        return new JsonResponse([
            'message' => $result->reason ?? 'Callback diterima.',
        ]);
    }
}
