<?php

namespace Tests\Fakes;

use App\Exceptions\MidtransApiException;
use App\Services\Payment\Midtrans\MidtransClient;
use Closure;
use LogicException;

/**
 * Midtrans, answering whatever a test tells it to.
 *
 * Bound in place of `MidtransClient` by `fakeMidtrans()` in `tests/Pest.php`.
 * A call nobody stubbed throws, so a test can never fall through to the real
 * sandbox — and one that should make no call at all can simply not stub it.
 */
final class FakeMidtransClient extends MidtransClient
{
    /** @var array<int, array<string, mixed>> */
    public array $charges = [];

    /** @var array<int, string> */
    public array $statusChecks = [];

    /** @var array<int, array{reference: string, params: array<string, mixed>}> */
    public array $refunds = [];

    /** @var array<string, mixed>|MidtransApiException|null */
    private array|MidtransApiException|null $chargeAnswer = null;

    /** @var array<string, mixed>|MidtransApiException|Closure(string): array<string, mixed>|null */
    private array|MidtransApiException|Closure|null $statusAnswer = null;

    /** @var array<string, mixed>|MidtransApiException|null */
    private array|MidtransApiException|null $refundAnswer = null;

    /**
     * @param  array<string, mixed>|MidtransApiException  $answer
     */
    public function chargeAnswers(array|MidtransApiException $answer): self
    {
        $this->chargeAnswer = $answer;

        return $this;
    }

    /**
     * @param  array<string, mixed>|MidtransApiException|Closure(string): array<string, mixed>  $answer
     */
    public function statusAnswers(array|MidtransApiException|Closure $answer): self
    {
        $this->statusAnswer = $answer;

        return $this;
    }

    /**
     * @param  array<string, mixed>|MidtransApiException  $answer
     */
    public function refundAnswers(array|MidtransApiException $answer): self
    {
        $this->refundAnswer = $answer;

        return $this;
    }

    public function charge(array $params): array
    {
        $this->charges[] = $params;

        return $this->give($this->chargeAnswer, 'charge');
    }

    public function status(string $reference): array
    {
        $this->statusChecks[] = $reference;

        if ($this->statusAnswer instanceof Closure) {
            return ($this->statusAnswer)($reference);
        }

        return $this->give($this->statusAnswer, 'status');
    }

    public function refund(string $reference, array $params): array
    {
        $this->refunds[] = ['reference' => $reference, 'params' => $params];

        return $this->give($this->refundAnswer, 'refund');
    }

    /**
     * @param  array<string, mixed>|MidtransApiException|null  $answer
     * @return array<string, mixed>
     */
    private function give(array|MidtransApiException|null $answer, string $call): array
    {
        if ($answer === null) {
            throw new LogicException("Midtrans {$call} was called, and the test did not say what it answers.");
        }

        if ($answer instanceof MidtransApiException) {
            throw $answer;
        }

        return $answer;
    }
}
