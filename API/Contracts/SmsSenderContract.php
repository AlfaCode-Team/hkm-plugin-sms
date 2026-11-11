<?php

declare(strict_types=1);

namespace Plugins\Sms\API\Contracts;

use Plugins\Sms\Domain\PhoneNumber;
use Plugins\Sms\Domain\SendResult;
use Plugins\Sms\Domain\Segmentation;

/**
 * The published surface of `sms.delivery`.
 *
 * The kernel's SmsPort is also bound by this plugin and is the right dependency
 * for code that only needs to send — it is `send(to, message): void` and
 * nothing more, so a repository or service typed against it stays portable.
 *
 * This contract exists for the things that port deliberately cannot express:
 * the provider's message id, what the message will cost, and the mock-number
 * mapping an OTP flow needs. Depend on the PORT unless you need one of those.
 */
interface SmsSenderContract
{
    /**
     * @throws \InvalidArgumentException when the destination or body is unusable
     * @throws \Plugins\Sms\Infrastructure\Exceptions\SmsException when the provider refuses
     */
    public function send(PhoneNumber|string $to, string $body, ?string $from = null): SendResult;

    /**
     * What this body would cost, without sending it.
     *
     * Worth calling before storing a template: one non-GSM character re-encodes
     * the whole message and cuts capacity from 160 characters to 70.
     */
    public function cost(string $body): Segmentation;

    /**
     * The fixed code configured for a mock destination, or null for a real one.
     *
     * An OTP flow calls this first and, on a non-null answer, uses that code and
     * skips generating one — which is what lets the flow be driven end to end in
     * a test or a review environment without an SMS being sent.
     */
    public function mockCodeFor(PhoneNumber|string $to): ?string;

    /**
     * True when a send to this destination will be swallowed instead of sent.
     *
     * Distinct from mockCodeFor() on purpose: a mock destination may be
     * configured WITHOUT a code, so a null code does not mean a real number.
     * Anything that warns an operator before sending needs this one.
     */
    public function isDiverted(PhoneNumber|string $to): bool;

    /** One line for a status command. Never contains a credential. */
    public function describe(): string;
}
