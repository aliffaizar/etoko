<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Midtrans did not give a usable answer (ADR-053).
 *
 * Raised by `MidtransClient` only, and caught by `MidtransGateway` only: it is
 * the SDK's bare `\Exception` given the two things the driver needs to tell
 * apart — a refusal Midtrans put into words, and a connection that never got
 * an answer at all. Nothing outside the driver should ever see it.
 */
final class MidtransApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body  What Midtrans answered, when it answered.
     */
    private function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly array $body = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Midtrans answered, and the answer was an error.
     *
     * @param  array<string, mixed>  $body
     */
    public static function refused(int $status, array $body = [], ?Throwable $previous = null): self
    {
        return new self("Midtrans menjawab dengan status {$status}.", $status, $body, $previous);
    }

    /**
     * No answer arrived: a timeout, a refused connection, a DNS failure.
     */
    public static function unreachable(?Throwable $previous = null): self
    {
        return new self('Midtrans tidak dapat dihubungi.', previous: $previous);
    }

    public function isUnreachable(): bool
    {
        return $this->status === null;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
