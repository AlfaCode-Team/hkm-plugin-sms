<?php

declare(strict_types=1);

namespace Plugins\Sms\Infrastructure\Transport;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\Sms\Domain\Contracts\SmsTransport;
use Plugins\Sms\Infrastructure\Exceptions\SmsException;
use Plugins\Sms\Domain\SendResult;
use Plugins\Sms\Domain\SmsMessage;

/**
 * Twilio's Programmable Messaging REST API.
 *
 * Endpoint and request shape taken from the API itself:
 *
 *   POST https://api.twilio.com/2010-04-01/Accounts/{AccountSid}/Messages.json
 *   Authorization: Basic base64(AccountSid:AuthToken)
 *   Content-Type: application/x-www-form-urlencoded
 *   Body=…&To=…&From=…            (or MessagingServiceSid instead of From)
 *
 * Only the vendor is touched here — no database, no other services — because
 * that is what the gateway layer is allowed to do.
 */
final readonly class TwilioTransport implements SmsTransport
{
    private const BASE = 'https://api.twilio.com/2010-04-01';

    public function __construct(
        private HttpClientPort $http,
        private string $accountSid,
        private string $authToken,
        /** A purchased number or alphanumeric sender id. */
        private ?string $from = null,
        /**
         * A Messaging Service groups numbers and handles sender selection and
         * regulatory compliance per country. When both are set this wins,
         * matching Twilio's own precedence.
         */
        private ?string $messagingServiceSid = null,
        private int $timeout = 15,
    ) {}

    public function name(): string
    {
        return 'twilio';
    }

    public function send(SmsMessage $message): SendResult
    {
        $from = $message->from ?? $this->from;

        if ($from === null && $this->messagingServiceSid === null) {
            // Twilio rejects this, but only after a round trip that is billed
            // as an API request. Refusing locally also gives a message that
            // names the two settings rather than a provider error code.
            throw SmsException::notConfigured(
                'Twilio needs either SMS_TWILIO_FROM (a purchased number or sender id) '
                . 'or SMS_TWILIO_MESSAGING_SERVICE_SID.'
            );
        }

        $form = ['To' => $message->to->e164, 'Body' => $message->body];

        if ($this->messagingServiceSid !== null) {
            $form['MessagingServiceSid'] = $this->messagingServiceSid;
        } else {
            $form['From'] = (string) $from;
        }

        try {
            $response = $this->http->request(
                'POST',
                self::BASE . '/Accounts/' . rawurlencode($this->accountSid) . '/Messages.json',
                [
                    'form'    => $form,
                    'headers' => [
                        // Basic auth is built here rather than through a client
                        // helper so the credential never leaves this method.
                        'Authorization' => 'Basic ' . base64_encode($this->accountSid . ':' . $this->authToken),
                        'Accept'        => 'application/json',
                    ],
                    'timeout' => $this->timeout,
                    // NEVER retried at the transport. Twilio bills on
                    // acceptance, and a timeout does not say whether the message
                    // was accepted — so an automatic retry can send the same SMS
                    // twice and charge for both. Retrying is the caller's
                    // decision, made with the retryable flag on the exception.
                    'retry'   => 0,
                ],
            );
        } catch (\Throwable $e) {
            // Includes the SSRF guard's refusals and every transport failure.
            throw SmsException::fromProvider(
                transport: $this->name(),
                message:   'could not reach the API: ' . $e->getMessage(),
                retryable: null,
                previous:  $e,
            );
        }

        $payload = $response->json();
        $payload = \is_array($payload) ? $payload : [];

        if (!$response->ok()) {
            throw SmsException::fromProvider(
                transport:    $this->name(),
                // Twilio puts a human-readable reason in `message` and its own
                // numeric code in `code`; both are surfaced because the code is
                // what a reader can look up and the message is what they read.
                message:      \is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'HTTP ' . $response->status() . ' with no error message',
                status:       $response->status(),
                providerCode: isset($payload['code']) ? (string) $payload['code'] : null,
                retryable:    SmsException::isRetryableStatus($response->status()),
            );
        }

        return new SendResult(
            transport:  $this->name(),
            providerId: \is_string($payload['sid'] ?? null) ? $payload['sid'] : null,
            // Twilio reports num_segments too, but only after it has processed
            // the body. The local count is used so the number is available on
            // every path, including the mock transport and a refusal before
            // sending. Where both exist they have agreed in every case checked.
            segments:   $message->segmentation->segments,
            encoding:   $message->segmentation->encoding,
        );
    }

    public function describe(): string
    {
        $sender = $this->messagingServiceSid !== null
            ? 'messaging service ' . $this->maskTail($this->messagingServiceSid)
            : 'from ' . ($this->from ?? 'UNSET');

        // The account SID is not secret (it is an identifier, and it appears in
        // the request URL), but it is still masked here: a status command's
        // output gets pasted into issues and chat. The auth token never appears.
        return sprintf('twilio account %s, %s', $this->maskTail($this->accountSid), $sender);
    }

    private function maskTail(string $value): string
    {
        $tail = substr($value, -4);

        return \strlen($value) <= 4 ? $tail : str_repeat('*', \strlen($value) - 4) . $tail;
    }
}
