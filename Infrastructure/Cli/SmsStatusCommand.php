<?php

declare(strict_types=1);

namespace Plugins\Sms\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Sms\Infrastructure\Exceptions\SmsException;
use Plugins\Sms\Infrastructure\Config\SmsConfig;
use Plugins\Sms\Infrastructure\SmsFactory;

/**
 * `sms:status` — say what is configured and whether it could send.
 *
 * It does NOT send anything and does not authenticate. That is a real limit and
 * it is stated in the output rather than implied: unlike FCM, neither Twilio nor
 * any other provider here offers a validate-only call, so the only way to prove
 * a credential works is to send a message to a real handset. `sms:send` is that,
 * and it costs money.
 *
 * What this does catch is every misconfiguration that stops a send being
 * attempted at all — an unknown transport name, missing credentials, no sender —
 * which is the majority of them, and all of the ones that are free to find.
 */
final class SmsStatusCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'sms:status';
        $this->description = 'Show the SMS configuration and whether a send could be attempted';
    }

    protected function handle(): int
    {
        $this->section('SMS');

        $config = SmsConfig::fromEnv();

        $this->info('Transport: ' . $config->transport
            . ' (available: ' . implode(', ', SmsFactory::available()) . ')');

        try {
            $transport = SmsFactory::transport($config, null, null);
            $this->success($transport->describe());
        } catch (SmsException $e) {
            // The twilio path legitimately reaches here when no HttpClientPort
            // is available in a bare CLI container, so a credential problem and
            // a wiring problem have to be distinguishable. The exception message
            // already names which one it is.
            $this->warning($e->getMessage());

            if ($config->transport === 'twilio') {
                $ready = $config->twilioAccountSid !== null && $config->twilioAuthToken !== null;
                $this->info('Twilio credentials: ' . ($ready ? 'present' : 'MISSING'));
                $this->info('Twilio sender: ' . (
                    $config->twilioMessagingServiceSid !== null
                        ? 'messaging service'
                        : ($config->twilioFrom ?? $config->from ?? 'UNSET')
                ));
            }

            return self::FAILURE;
        }

        if ($config->maxSegments !== null) {
            $this->info("Segment ceiling: {$config->maxSegments}");
        }

        if ($config->mockNumbers !== []) {
            $this->warning(sprintf(
                '%d destination(s) are DIVERTED and will never be sent to. Any real '
                . 'number listed here silently receives nothing.',
                \count($config->mockNumbers),
            ));

            foreach ($config->mockNumbers as $number => $code) {
                // Masked: this output gets pasted into issues and chat, and
                // these are real destinations.
                $masked = \Plugins\Sms\Domain\PhoneNumber::parse($number)->masked();
                $this->muted('  ' . $masked . ($code === '' ? '' : ' → fixed code set'));
            }
        }

        $this->newLine();
        $this->muted('Nothing was sent and no credential was verified: no provider here offers a');
        $this->muted('validate-only call, so only a real send to a real handset proves delivery.');

        return self::SUCCESS;
    }
}
