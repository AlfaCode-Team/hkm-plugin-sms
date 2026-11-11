<?php

declare(strict_types=1);

namespace Plugins\Sms\Domain\Contracts;

use Plugins\Sms\Domain\SendResult;
use Plugins\Sms\Domain\SmsMessage;

/**
 * One way of getting an SMS to a handset.
 *
 * Implementations live in Infrastructure/Transport and may ONLY talk to their
 * vendor — no database, no other services. A transport that threw a vendor
 * exception would leak it past the gateway layer, so each one translates
 * failures to SmsException.
 */
interface SmsTransport
{
    /** Short, stable, lower-case identifier: 'twilio', 'log', 'mock'. */
    public function name(): string;

    /**
     * Hand the message to the provider.
     *
     * @throws \Plugins\Sms\Infrastructure\Exceptions\SmsException when the provider
     *         refuses or cannot be reached
     */
    public function send(SmsMessage $message): SendResult;

    /**
     * One line describing how this transport is configured, for a status
     * command. MUST NOT contain a credential.
     */
    public function describe(): string;
}
