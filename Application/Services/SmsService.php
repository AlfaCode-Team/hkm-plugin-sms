<?php

declare(strict_types=1);

namespace Plugins\Sms\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Sms\API\Contracts\SmsSenderContract;
use Plugins\Sms\Domain\Contracts\SmsTransport;
use Plugins\Sms\Domain\PhoneNumber;
use Plugins\Sms\Domain\Segmentation;
use Plugins\Sms\Domain\SendResult;
use Plugins\Sms\Domain\SmsMessage;
use Plugins\Sms\Infrastructure\Config\SmsConfig;
use Plugins\Sms\Infrastructure\Transport\MockTransport;

/**
 * Sends an SMS through the configured transport, with two things in front of it.
 *
 *  1. VALIDATION AND COST, before the request. A malformed number is refused
 *     here rather than accepted, queued and billed by a provider that reports
 *     the failure hours later through a channel this plugin does not yet read.
 *
 *  2. MOCK-NUMBER DIVERSION. A destination listed in SMS_MOCK_NUMBERS never
 *     reaches a provider, whatever the active transport is.
 */
final class SmsService implements SmsSenderContract
{
    public function __construct(
        private readonly SmsTransport $transport,
        private readonly SmsConfig $config,
        private readonly ?LoggerPort $logger = null,
        /**
         * Where diverted messages go. Injected so a test can read what a mock
         * destination received, and shared across calls so the record
         * accumulates.
         */
        private readonly MockTransport $mock = new MockTransport(),
    ) {}

    public function send(PhoneNumber|string $to, string $body, ?string $from = null): SendResult
    {
        $message = SmsMessage::to(
            to:          $to,
            body:        $body,
            from:        $from ?? $this->config->from,
            maxSegments: $this->config->maxSegments,
        );

        if ($this->config->isMock($message->to)) {
            // Warning, not info. A diverted message in production means an
            // operator put a real customer's number in SMS_MOCK_NUMBERS, and
            // the symptom — one specific person never receiving anything — is
            // close to undiagnosable without this line.
            $this->logger?->warning('sms.diverted_to_mock', [
                'to'                   => $message->to->masked(),
                'configured_transport' => $this->transport->name(),
            ]);

            return $this->mock->send($message);
        }

        $result = $this->transport->send($message);

        $this->logger?->info('sms.sent', [
            'to'          => $message->to->masked(),
            'transport'   => $result->transport,
            'provider_id' => $result->providerId,
            'segments'    => $result->segments,
            'encoding'    => $result->encoding->value,
            'simulated'   => $result->simulated,
        ]);

        return $result;
    }

    public function cost(string $body): Segmentation
    {
        return Segmentation::of($body);
    }

    public function mockCodeFor(PhoneNumber|string $to): ?string
    {
        return $this->config->mockCodeFor($this->number($to));
    }

    public function isDiverted(PhoneNumber|string $to): bool
    {
        return $this->config->isMock($this->number($to));
    }

    private function number(PhoneNumber|string $to): PhoneNumber
    {
        return $to instanceof PhoneNumber ? $to : PhoneNumber::parse($to);
    }

    public function describe(): string
    {
        $parts = [$this->transport->describe()];

        if ($this->config->maxSegments !== null) {
            $parts[] = "ceiling {$this->config->maxSegments} segment(s)";
        }

        if ($this->config->mockNumbers !== []) {
            // Count, not the numbers: this output gets pasted around, and the
            // numbers are destinations.
            $parts[] = \count($this->config->mockNumbers) . ' mock destination(s) diverted';
        }

        return implode('; ', $parts);
    }

    /** The diverted-message record, for tests. */
    public function mockTransport(): MockTransport
    {
        return $this->mock;
    }
}
