<?php

declare(strict_types=1);

namespace Tests\Plugins\Sms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Sms\Infrastructure\Exceptions\SmsException;
use Plugins\Sms\Domain\SmsMessage;
use Plugins\Sms\Infrastructure\Transport\TwilioTransport;
use Tests\Plugins\Sms\Support\FakeHttpClient;

/**
 * The exact request this transport builds, against Twilio's published shape.
 *
 * This is the half of the behaviour the plugin owns, and it is where the
 * expensive mistakes are: a field named `Message` instead of `Body`, the
 * credential in the wrong header, `To` and `From` transposed. Each of those
 * produces either a billed request that delivers nothing or an error that reads
 * like a credential problem.
 *
 * No request has ever reached Twilio from this suite — see the README.
 */
#[CoversClass(TwilioTransport::class)]
final class TwilioTransportTest extends TestCase
{
    private const SID   = 'AC00000000000000000000000000000001';
    private const TOKEN = 'secret-auth-token';

    private function transport(FakeHttpClient $http, ?string $from = '+14155550100', ?string $service = null): TwilioTransport
    {
        return new TwilioTransport(
            http:                $http,
            accountSid:          self::SID,
            authToken:           self::TOKEN,
            from:                $from,
            messagingServiceSid: $service,
        );
    }

    private function message(string $body = 'Your code is 123456'): SmsMessage
    {
        return SmsMessage::to('+256700000000', $body);
    }

    public function test_the_request_matches_the_published_api_shape(): void
    {
        $http = (new FakeHttpClient())->willReturnJson(201, ['sid' => 'SM123', 'status' => 'queued']);

        $this->transport($http)->send($this->message());

        $request = $http->lastRequest();

        self::assertSame('POST', $request['method']);
        self::assertSame(
            'https://api.twilio.com/2010-04-01/Accounts/' . self::SID . '/Messages.json',
            $request['url'],
        );

        // Form-encoded, not JSON. Twilio's API does not accept a JSON body here,
        // and `json` would set the wrong Content-Type.
        self::assertSame([
            'To'   => '+256700000000',
            'Body' => 'Your code is 123456',
            'From' => '+14155550100',
        ], $http->lastForm());
    }

    public function test_the_credential_goes_in_basic_auth_and_not_the_body(): void
    {
        $http = (new FakeHttpClient())->willReturnJson(201, ['sid' => 'SM123']);

        $this->transport($http)->send($this->message());

        $headers = $http->lastHeaders();

        self::assertSame(
            'Basic ' . base64_encode(self::SID . ':' . self::TOKEN),
            $headers['Authorization'] ?? null,
        );

        // The auth token must never appear in the form, where it would be
        // logged by any proxy recording request bodies.
        self::assertSame([], array_filter(
            $http->lastForm(),
            static fn (mixed $v): bool => \is_string($v) && str_contains($v, self::TOKEN),
        ));
    }

    public function test_a_messaging_service_replaces_the_from_number(): void
    {
        $http = (new FakeHttpClient())->willReturnJson(201, ['sid' => 'SM123']);

        $this->transport($http, from: '+14155550100', service: 'MG999')->send($this->message());

        $form = $http->lastForm();

        // Twilio's own precedence: a messaging service selects the sender, so
        // sending From as well is contradictory.
        self::assertSame('MG999', $form['MessagingServiceSid'] ?? null);
        self::assertArrayNotHasKey('From', $form);
    }

    public function test_it_never_lets_the_client_retry(): void
    {
        $http = (new FakeHttpClient())->willReturnJson(201, ['sid' => 'SM123']);

        $this->transport($http)->send($this->message());

        // Twilio bills on acceptance and a timeout does not say whether the
        // message was accepted, so an automatic retry can deliver the same SMS
        // twice and charge for both. An SMS cannot be recalled.
        self::assertSame(0, $http->lastRequest()['options']['retry'] ?? null);
    }

    public function test_it_returns_the_provider_id_and_the_local_segment_count(): void
    {
        $http = (new FakeHttpClient())->willReturnJson(201, ['sid' => 'SM_abc', 'num_segments' => '1']);

        $result = $this->transport($http)->send($this->message(str_repeat('a', 161)));

        self::assertSame('twilio', $result->transport);
        self::assertSame('SM_abc', $result->providerId);
        self::assertFalse($result->simulated);
        // Counted locally so the number exists on every path, including a
        // refusal that never reaches the provider.
        self::assertSame(2, $result->segments);
    }

    public function test_a_provider_rejection_surfaces_its_message_and_code(): void
    {
        $http = (new FakeHttpClient())->willReturnJson(400, [
            'code'    => 21211,
            'message' => "The 'To' number +256700000000 is not a valid phone number.",
        ]);

        try {
            $this->transport($http)->send($this->message());
            self::fail('expected an SmsException');
        } catch (SmsException $e) {
            // The human-readable reason is what a reader acts on; the numeric
            // code is what they can look up. Both are surfaced.
            self::assertStringContainsString('not a valid phone number', $e->getMessage());
            self::assertSame('21211', $e->context['provider_code'] ?? null);
            self::assertSame(400, $e->context['status'] ?? null);
            self::assertFalse($e->context['retryable'] ?? null);
        }
    }

    public function test_a_rate_limit_is_marked_retryable_and_a_rejection_is_not(): void
    {
        foreach ([[429, true], [500, true], [503, true], [400, false], [401, false], [404, false]] as [$status, $retryable]) {
            $http = (new FakeHttpClient())->willReturnJson($status, ['message' => 'nope']);

            try {
                $this->transport($http)->send($this->message());
                self::fail("expected a failure for {$status}");
            } catch (SmsException $e) {
                // Every 4xx below 429 rejects THIS message and will reject it
                // identically on every attempt, so retrying only multiplies the
                // failure and, where the provider bills on acceptance, the cost.
                self::assertSame($retryable, $e->context['retryable'] ?? null, "status {$status}");
            }
        }
    }

    public function test_an_error_body_that_is_not_json_still_produces_a_usable_message(): void
    {
        $http = (new FakeHttpClient())->willReturn(502, '<html>Bad Gateway</html>');

        try {
            $this->transport($http)->send($this->message());
            self::fail('expected an SmsException');
        } catch (SmsException $e) {
            self::assertStringContainsString('HTTP 502', $e->getMessage());
            self::assertTrue($e->context['retryable'] ?? null);
        }
    }

    public function test_a_transport_failure_is_translated_not_leaked(): void
    {
        $http = (new FakeHttpClient())->willThrow(new \RuntimeException('connection refused'));

        try {
            $this->transport($http)->send($this->message());
            self::fail('expected an SmsException');
        } catch (SmsException $e) {
            // A vendor or transport exception escaping the gateway layer would
            // break the kernel's error contract.
            self::assertStringContainsString('could not reach the API', $e->getMessage());
            self::assertStringContainsString('connection refused', $e->getMessage());
            // Unknown, not false: the request may have been accepted before the
            // connection broke, so the caller must not retry automatically.
            self::assertArrayNotHasKey('retryable', $e->context);
        }
    }

    public function test_no_sender_at_all_is_refused_before_a_billed_round_trip(): void
    {
        $http = new FakeHttpClient(); // nothing scripted: nothing may be sent

        try {
            $this->transport($http, from: null)->send($this->message());
            self::fail('expected an SmsException');
        } catch (SmsException $e) {
            self::assertStringContainsString('SMS_TWILIO_FROM', $e->getMessage());
        }

        self::assertSame([], $http->requests, 'nothing should have been sent');
    }

    public function test_describe_never_reveals_the_auth_token(): void
    {
        $described = $this->transport(new FakeHttpClient())->describe();

        self::assertStringNotContainsString(self::TOKEN, $described);
        // The account SID is an identifier rather than a secret, but status
        // output gets pasted into issues, so it is masked too.
        self::assertStringNotContainsString(self::SID, $described);
        self::assertStringContainsString('twilio account', $described);
    }
}
