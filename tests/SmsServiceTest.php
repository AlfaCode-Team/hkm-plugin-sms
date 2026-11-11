<?php

declare(strict_types=1);

namespace Tests\Plugins\Sms;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SmsPort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Sms\Application\Services\SmsService;
use Plugins\Sms\Infrastructure\Exceptions\SmsException;
use Plugins\Sms\Domain\SmsMessage;
use Plugins\Sms\Infrastructure\Config\SmsConfig;
use Plugins\Sms\Infrastructure\SmsFactory;
use Plugins\Sms\Infrastructure\SmsPortAdapter;
use Plugins\Sms\Infrastructure\Transport\MockTransport;

#[CoversClass(SmsService::class)]
#[CoversClass(SmsPortAdapter::class)]
#[CoversClass(SmsFactory::class)]
#[CoversClass(SmsConfig::class)]
#[CoversClass(MockTransport::class)]
#[CoversClass(SmsMessage::class)]
final class SmsServiceTest extends TestCase
{
    private function service(SmsConfig $config, ?MockTransport $transport = null): SmsService
    {
        return new SmsService(
            transport: $transport ?? new MockTransport(),
            config:    $config,
        );
    }

    public function test_it_sends_through_the_configured_transport(): void
    {
        $transport = new MockTransport();
        $service   = $this->service(SmsConfig::of(transport: 'mock'), $transport);

        $result = $service->send('+256 700 000 000', 'Your code is 123456');

        self::assertSame('mock', $result->transport);
        self::assertTrue($result->simulated);
        // Normalised on the way through, so a caller's formatting does not
        // reach the provider.
        self::assertSame('+256700000000', $transport->lastTo());
        self::assertSame('Your code is 123456', $transport->lastBody());
    }

    public function test_a_mock_destination_never_reaches_the_transport(): void
    {
        $transport = new MockTransport();
        $service   = $this->service(
            SmsConfig::of(transport: 'mock', mockNumbers: ['+256700000000' => '123456']),
            $transport,
        );

        $result = $service->send('+256700000000', 'Your code is 999999');

        // The configured transport saw nothing at all: the message went to the
        // diversion record instead.
        self::assertSame([], $transport->sent());
        self::assertCount(1, $service->mockTransport()->sent());
        self::assertTrue($result->simulated);
    }

    public function test_the_mock_number_mapping_is_normalised_before_matching(): void
    {
        // Configured one way, called another. If these did not normalise to the
        // same key, a mock destination would silently be sent to for real.
        $service = $this->service(
            SmsConfig::of(transport: 'mock', mockNumbers: ['00256 700 000 000' => '123456']),
        );

        self::assertTrue($service->isDiverted('+256700000000'));
        self::assertSame('123456', $service->mockCodeFor('+256-700-000-000'));
    }

    public function test_a_real_destination_has_no_mock_code_and_is_not_diverted(): void
    {
        $service = $this->service(
            SmsConfig::of(transport: 'mock', mockNumbers: ['+256700000000' => '123456']),
        );

        self::assertNull($service->mockCodeFor('+14155550100'));
        self::assertFalse($service->isDiverted('+14155550100'));
    }

    public function test_a_mock_number_without_a_code_is_still_diverted(): void
    {
        // This is why isDiverted() exists separately from mockCodeFor(): a null
        // code does not mean a real number.
        $service = $this->service(SmsConfig::of(transport: 'mock', mockNumbers: ['+256700000000' => '']));

        self::assertNull($service->mockCodeFor('+256700000000'));
        self::assertTrue($service->isDiverted('+256700000000'));
    }

    public function test_the_segment_ceiling_refuses_before_sending(): void
    {
        $transport = new MockTransport();
        $service   = $this->service(SmsConfig::of(transport: 'mock', maxSegments: 1), $transport);

        try {
            $service->send('+256700000000', str_repeat('a', 161));
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            // The message has to name the encoding, because the usual cause is
            // one invisible character having halved the capacity.
            self::assertStringContainsString('2 segments', $e->getMessage());
            self::assertStringContainsString('160 to 70', $e->getMessage());
        }

        self::assertSame([], $transport->sent(), 'nothing should have been sent');
    }

    public function test_cost_reports_without_sending(): void
    {
        $transport = new MockTransport();
        $service   = $this->service(SmsConfig::of(transport: 'mock'), $transport);

        $cost = $service->cost("Don\u{2019}t share this code");

        self::assertSame('UCS-2', $cost->encoding->value);
        self::assertSame([], $transport->sent());
    }

    public function test_an_empty_body_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service(SmsConfig::of(transport: 'mock'))->send('+256700000000', '   ');
    }

    public function test_the_kernel_port_delegates_and_returns_void(): void
    {
        $transport = new MockTransport();
        $service   = $this->service(SmsConfig::of(transport: 'mock'), $transport);

        $port = new SmsPortAdapter($service);

        self::assertInstanceOf(SmsPort::class, $port);
        $port->send('+256700000000', 'via the port');

        self::assertSame('via the port', $transport->lastBody());
    }

    public function test_the_kernel_port_raises_on_failure_because_void_has_no_other_channel(): void
    {
        $transport = new MockTransport();
        $transport->failNext('provider said no');

        $port = new SmsPortAdapter($this->service(SmsConfig::of(transport: 'mock'), $transport));

        $this->expectException(SmsException::class);
        $port->send('+256700000000', 'body');
    }

    public function test_an_unknown_transport_name_is_refused_rather_than_defaulted(): void
    {
        try {
            SmsFactory::transport(SmsConfig::of(transport: 'twillio')); // deliberate typo
            self::fail('expected a refusal');
        } catch (SmsException $e) {
            // Falling back to 'log' would mean every message in production goes
            // to a log file while every call reports success.
            self::assertStringContainsString('twillio', $e->getMessage());
            self::assertStringContainsString('twilio, log, mock', $e->getMessage());
        }
    }

    public function test_the_twilio_transport_needs_an_http_client(): void
    {
        $this->expectException(SmsException::class);
        $this->expectExceptionMessageMatches('/http\.client/');

        SmsFactory::transport(
            SmsConfig::of(transport: 'twilio', twilioAccountSid: 'AC1', twilioAuthToken: 't'),
            http: null,
        );
    }

    public function test_describe_reports_diversions_as_a_count_not_as_numbers(): void
    {
        $service = $this->service(SmsConfig::of(
            transport:   'mock',
            mockNumbers: ['+256700000000' => '1', '+14155550100' => '2'],
        ));

        $described = $service->describe();

        self::assertStringContainsString('2 mock destination(s) diverted', $described);
        // Status output gets pasted around, and these are real destinations.
        self::assertStringNotContainsString('256700000000', $described);
    }

    public function test_config_parsing_of_mock_numbers(): void
    {
        $_ENV['SMS_MOCK_NUMBERS'] = '+256700000000:123456, +1 415 555 0100:000000, nonsense, +notanumber:1';

        try {
            $config = SmsConfig::fromEnv();

            // A malformed entry is skipped rather than fatal: this is a
            // development convenience, and a stray comma must not take a
            // production boot down.
            self::assertSame(
                ['+256700000000' => '123456', '+14155550100' => '000000'],
                $config->mockNumbers,
            );
        } finally {
            unset($_ENV['SMS_MOCK_NUMBERS']);
        }
    }

    public function test_max_segments_zero_means_no_ceiling(): void
    {
        $_ENV['SMS_MAX_SEGMENTS'] = '0';

        try {
            // An int-typed config var cannot be absent — ValidateConfigStage
            // reads '' as missing — so 0 is the only way to say "unset".
            self::assertNull(SmsConfig::fromEnv()->maxSegments);
        } finally {
            unset($_ENV['SMS_MAX_SEGMENTS']);
        }
    }
}
