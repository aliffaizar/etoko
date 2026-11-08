<?php

namespace App\Actions\Payment;

use App\Enums\PaymentStatus;
use App\Exceptions\PaymentFailedException;
use App\Models\Order;
use App\Models\Payment;
use App\Scopes\BranchScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Takes the customer's receipt for a manual transfer (P2-E1-T3).
 *
 * The receipt is **evidence, not a settlement**: uploading it does not mark
 * the order paid, because a screenshot is not money. What it does is move the
 * order's `payment_status` to `pending` — "Menunggu Konfirmasi" — which is
 * what puts the payment into the verification queue in `P2-E1-T4` and makes
 * that filter mean *there is something for me to look at*.
 *
 * Re-uploading is allowed while the attempt is open, because the first attempt
 * at photographing a bank app is often unreadable. The previous file is
 * deleted rather than orphaned: the only reason to keep it would be to prove
 * what was sent first, and a replaced receipt is the customer correcting
 * themselves, not covering their tracks.
 *
 * The stored name is generated, not the uploaded one: two customers uploading
 * `bukti.jpg` must not collide, and a public URL should not carry
 * `WhatsApp Image 2026-11-08 at 14.32.jpg` — the same reasoning as
 * `StoreProductImages`.
 */
final class SubmitPaymentProof
{
    /**
     * Attach the receipt to the attempt.
     *
     * @param  string|null  $note  The customer's own words: which bank, whose name.
     *
     * @throws PaymentFailedException
     */
    public function handle(Payment $payment, UploadedFile $proof, ?string $note = null): Payment
    {
        $this->assertAcceptsProof($payment);

        $path = $proof->store(
            Payment::PROOF_DIRECTORY.'/'.$payment->order_id,
            Payment::PROOF_DISK,
        );

        if (! is_string($path)) {
            throw PaymentFailedException::unsupported(
                'Bukti transfer gagal disimpan. Coba unggah ulang.',
            );
        }

        $previous = $payment->proof_path;

        DB::transaction(function () use ($payment, $path, $note): void {
            $locked = $this->lock($payment);

            // Re-checked under the lock: a staff member may have verified or
            // rejected the attempt while the file was uploading, and a receipt
            // attached to a settled payment would read as evidence nobody
            // looked at.
            $this->assertAcceptsProof($locked);

            $locked->update([
                'proof_path' => $path,
                'proof_note' => $note,
                'proof_uploaded_at' => now(),
            ]);

            $order = Order::withoutGlobalScope(BranchScope::class)
                ->whereKey($locked->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $order->update(['payment_status' => PaymentStatus::Pending]);
        });

        if ($previous !== null && $previous !== $path) {
            Storage::disk(Payment::PROOF_DISK)->delete($previous);
        }

        return $payment->refresh();
    }

    /**
     * @throws PaymentFailedException
     */
    private function assertAcceptsProof(Payment $payment): void
    {
        if (! $payment->isOpen()) {
            throw PaymentFailedException::unsupported(
                "Pembayaran ini sudah berstatus \"{$payment->status->label()}\", "
                    .'jadi bukti transfer tidak dapat diunggah lagi.',
            );
        }

        if (! $payment->method_type->expectsProofOfTransfer()) {
            throw PaymentFailedException::unsupported(
                "Metode \"{$payment->method_name}\" tidak memerlukan unggahan bukti transfer.",
            );
        }

        if ($payment->hasOverrun()) {
            throw PaymentFailedException::unsupported(
                'Batas waktu pembayaran pesanan ini sudah terlewat. Hubungi cabang bila dana sudah terkirim.',
            );
        }
    }

    private function lock(Payment $payment): Payment
    {
        return Payment::withoutGlobalScope(BranchScope::class)
            ->whereKey($payment->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
