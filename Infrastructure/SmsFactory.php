<?php

declare(strict_types=1);

namespace Plugins\Sms\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Sms\Application\Services\SmsService;
use Plugins\Sms\Domain\Contracts\SmsTransport;
use Plugins\Sms\Infrastructure\Exceptions\SmsException;
use Plugins\Sms\Infrastructure\Config\SmsConfig;
use Plugins\Sms\Infrastructure\Transport\LogTransport;
use Plugins\Sms\Infrastructure\Transport\MockTransport;
use Plugins\Sms\Infrastructure\Transport\TwilioTransport;

/**
 * Chooses the transport named by SMS_TRANSPORT and assembles the service.
 */
final class SmsFactory
{
    public static function make(
        SmsConfig $config,
        ?HttpClientPort $http = null,
        ?LoggerPort $logger = null,
    ): SmsService {
        return new SmsService(
            transport: self::transport($config, $http, $logger),
            config:    $config,
            logger:    $logger,
        );
    }

    public static function transport(
        SmsConfig $config,
        ?HttpClientPort $http = null,
        ?LoggerPort $logger = null,
    ): SmsTransport {
        return match ($config->transport) {
            'twilio' => self::twilio($config, $http),
            'mock'   => new MockTransport(),
            'log'    => new LogTransport($logger),
            // An unknown name is REFUSED rather than quietly falling back to
            // 'log'. A typo in SMS_TRANSPORT would otherwise mean every message
            // in production goes to a log file while every call reports success
            // — which is the single worst outcome available here.
            default  => throw SmsException::notConfigured(sprintf(
                'SMS_TRANSPORT is [%s], which is not a transport this plugin has. Use one of: %s.',
                $config->transport,
                implode(', ', self::available()),
            )),
        };
    }

    /** @return list<string> */
    public static function available(): array
    {
        return ['twilio', 'log', 'mock'];
    }

    private static function twilio(SmsConfig $config, ?HttpClientPort $http): TwilioTransport
    {
        if ($http === null) {
            throw SmsException::notConfigured(
                'the twilio transport needs the http.client domain; add the HttpClient '
                . 'plugin, or this module\'s requires[] is not being honoured.'
            );
        }

        if ($config->twilioAccountSid === null || $config->twilioAuthToken === null) {
            throw SmsException::notConfigured(
                'the twilio transport needs SMS_TWILIO_ACCOUNT_SID and SMS_TWILIO_AUTH_TOKEN.'
            );
        }

        return new TwilioTransport(
            http:                $http,
            accountSid:          $config->twilioAccountSid,
            authToken:           $config->twilioAuthToken,
            from:                $config->twilioFrom ?? $config->from,
            messagingServiceSid: $config->twilioMessagingServiceSid,
            timeout:             $config->timeout,
        );
    }
}
