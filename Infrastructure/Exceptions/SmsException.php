<?php

declare(strict_types=1);

namespace Plugins\Sms\Infrastructure\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;

/**
 * A provider refused, or the transport could not reach it.
 *
 * Extends GatewayException because that is the kernel's channel for "a vendor
 * said no", and because its HTTP mapping (502) is the right answer: the
 * application is fine and the dependency is not.
 *
 * NOTHING HERE CARRIES A CREDENTIAL OR A FULL PHONE NUMBER. The context is
 * written to logs and error notifiers, and an auth token in a 401 diagnostic is
 * how a provider credential ends up in a log aggregator. Destinations appear
 * masked; tokens never appear at all.
 */
final class SmsException extends GatewayException
{
    /**
     * @param ?bool $retryable null when the transport cannot tell. Callers
     *                         treat null as "do not retry", because retrying a
     *                         send that may have been accepted can deliver the
     *                         same message twice and an SMS cannot be recalled.
     */
    public static function fromProvider(
        string $transport,
        string $message,
        ?int $status = null,
        ?string $providerCode = null,
        ?bool $retryable = null,
        ?\Throwable $previous = null,
    ): self {
        $context = array_filter(
            [
                'transport'     => $transport,
                'status'        => $status,
                'provider_code' => $providerCode,
                'retryable'     => $retryable,
            ],
            static fn (mixed $value): bool => $value !== null,
        );

        return new self(
            "SMS send failed via [{$transport}]: {$message}",
            layer:    'gateway.sms.' . $transport,
            context:  $context,
            previous: $previous,
        );
    }

    public static function notConfigured(string $detail): self
    {
        return new self(
            "SMS is not configured: {$detail}",
            layer: 'gateway.sms',
        );
    }

    /**
     * A status that means "try again later" — and only those.
     *
     * 429 and 5xx are the provider's own transient signals. Every 4xx below 429
     * is a rejection of THIS message (bad number, unverified sender, blocked
     * recipient) and will be rejected identically on every attempt, so retrying
     * only multiplies the failure and, where the provider bills on acceptance,
     * the cost.
     */
    public static function isRetryableStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }
}
