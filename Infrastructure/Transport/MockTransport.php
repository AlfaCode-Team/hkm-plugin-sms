<?php

declare(strict_types=1);

namespace Plugins\Sms\Infrastructure\Transport;

use Plugins\Sms\Domain\Contracts\SmsTransport;
use Plugins\Sms\Infrastructure\Exceptions\SmsException;
use Plugins\Sms\Domain\SendResult;
use Plugins\Sms\Domain\SmsMessage;

/**
 * Keeps every message in memory. Sends nothing.
 *
 * WHY A PLUGIN SHIPS THIS RATHER THAN LEAVING IT TO EACH TEST SUITE
 * -----------------------------------------------------------------
 * An OTP flow cannot be tested end to end without one. The alternatives are
 * spending real money per test run, or stubbing the port in every suite that
 * touches sign-in — and a hand-rolled stub per suite is how the stub and the
 * real transport drift until the tests pass against behaviour no provider has.
 *
 * This one is held to the same contract as Twilio: the same validation runs
 * before it (because that happens in SmsMessage), and `failNext()` exists so
 * the failure path gets exercised too. A test suite that only ever sees success
 * has not tested the thing that breaks.
 *
 * Not thread- or coroutine-safe, and deliberately so: it is per-process test
 * state, and anything shared would have to be in a store.
 */
final class MockTransport implements SmsTransport
{
    /** @var list<SmsMessage> */
    private array $sent = [];

    private ?string $failNext = null;

    public function name(): string
    {
        return 'mock';
    }

    public function send(SmsMessage $message): SendResult
    {
        if ($this->failNext !== null) {
            $reason          = $this->failNext;
            $this->failNext  = null;

            throw SmsException::fromProvider(
                transport: $this->name(),
                message:   $reason,
                status:    400,
                retryable: false,
            );
        }

        $this->sent[] = $message;

        return new SendResult(
            transport:  $this->name(),
            providerId: 'mock-' . \count($this->sent),
            segments:   $message->segmentation->segments,
            encoding:   $message->segmentation->encoding,
            simulated:  true,
        );
    }

    /** Make the next send() throw, so a test can cover the failure branch. */
    public function failNext(string $reason = 'mock failure'): void
    {
        $this->failNext = $reason;
    }

    /** @return list<SmsMessage> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function lastTo(): ?string
    {
        $last = $this->sent[\count($this->sent) - 1] ?? null;

        return $last?->to->e164;
    }

    public function lastBody(): ?string
    {
        $last = $this->sent[\count($this->sent) - 1] ?? null;

        return $last?->body;
    }

    /**
     * Messages sent to one destination, newest last.
     *
     * Takes the number through PhoneNumber-normalised comparison via the stored
     * E.164 form, so a test asserting on '+256 700 000 000' matches a message
     * sent to '+256700000000'.
     *
     * @return list<SmsMessage>
     */
    public function sentTo(string $e164): array
    {
        $wanted = \Plugins\Sms\Domain\PhoneNumber::isValid($e164)
            ? \Plugins\Sms\Domain\PhoneNumber::parse($e164)->e164
            : $e164;

        return array_values(array_filter(
            $this->sent,
            static fn (SmsMessage $m): bool => $m->to->e164 === $wanted,
        ));
    }

    public function reset(): void
    {
        $this->sent     = [];
        $this->failNext = null;
    }

    public function describe(): string
    {
        return sprintf('mock (nothing is sent; %d message(s) held in memory)', \count($this->sent));
    }
}
