<?php

use App\Actions\Payment\FailPayment;
use App\Actions\Payment\SubmitPaymentProof;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentFailedException;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * The receipt for a manual transfer (P2-E1-T3).
 *
 * Evidence, not a settlement: uploading moves the order to "Menunggu
 * Konfirmasi" so it enters the verification queue, and nothing more. A
 * screenshot is not money.
 */

beforeEach(function () {
    Storage::fake(Payment::PROOF_DISK);

    $this->branch = Branch::factory()->default()->create();
    $this->order = Order::factory()->forBranch($this->branch)->create([
        'payment_status' => PaymentStatus::Unpaid,
    ]);
    $this->payment = Payment::factory()->forOrder($this->order)->create();
    $this->submit = app(SubmitPaymentProof::class);
});

it('stores the file and stamps when it arrived', function () {
    $payment = $this->submit->handle(
        $this->payment,
        UploadedFile::fake()->image('bukti.jpg'),
        'Transfer dari BCA a/n Budi',
    );

    expect($payment->proof_path)->not->toBeNull()
        ->and($payment->proof_note)->toBe('Transfer dari BCA a/n Budi')
        ->and($payment->proof_uploaded_at)->not->toBeNull();

    Storage::disk(Payment::PROOF_DISK)->assertExists($payment->proof_path);
});

it('files the receipt under the order it belongs to', function () {
    $payment = $this->submit->handle($this->payment, UploadedFile::fake()->image('bukti.jpg'));

    expect($payment->proof_path)
        ->toStartWith(Payment::PROOF_DIRECTORY.'/'.$this->order->getKey().'/');
});

it('does not use the uploaded file name', function () {
    $payment = $this->submit->handle(
        $this->payment,
        UploadedFile::fake()->image('WhatsApp Image 2026-11-08 at 14.32.jpg'),
    );

    expect($payment->proof_path)->not->toContain('WhatsApp');
});

it('puts the order into the verification queue without marking it paid', function () {
    $this->submit->handle($this->payment, UploadedFile::fake()->image('bukti.jpg'));

    expect($this->order->fresh()->payment_status)->toBe(PaymentStatus::Pending)
        ->and($this->payment->fresh()->isOpen())->toBeTrue();
});

it('replaces an earlier receipt instead of leaving it orphaned', function () {
    $first = $this->submit->handle($this->payment, UploadedFile::fake()->image('satu.jpg'))->proof_path;
    $second = $this->submit->handle($this->payment->fresh(), UploadedFile::fake()->image('dua.jpg'))->proof_path;

    expect($second)->not->toBe($first);

    Storage::disk(Payment::PROOF_DISK)->assertMissing($first);
    Storage::disk(Payment::PROOF_DISK)->assertExists($second);
});

it('refuses a receipt for an attempt that has already been decided', function () {
    app(FailPayment::class)->handle($this->payment, 'Nominal tidak sesuai.');

    $this->submit->handle($this->payment->fresh(), UploadedFile::fake()->image('bukti.jpg'));
})->throws(PaymentFailedException::class, 'tidak dapat diunggah lagi');

it('refuses a receipt for a method that does not take one', function () {
    $cod = Payment::factory()
        ->forOrder($this->order)
        ->forMethod(PaymentMethod::factory()->cod()->create())
        ->create();

    $this->submit->handle($cod, UploadedFile::fake()->image('bukti.jpg'));
})->throws(PaymentFailedException::class, 'tidak memerlukan unggahan');

it('refuses a receipt once the deadline has passed', function () {
    $this->payment->update(['expires_at' => now()->subMinute()]);

    $this->submit->handle($this->payment->fresh(), UploadedFile::fake()->image('bukti.jpg'));
})->throws(PaymentFailedException::class, 'Batas waktu pembayaran');
