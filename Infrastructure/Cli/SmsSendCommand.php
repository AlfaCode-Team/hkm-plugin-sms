<?php

declare(strict_types=1);

namespace Plugins\Sms\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Sms\API\Contracts\SmsSenderContract;
use Plugins\Sms\Infrastructure\Exceptions\SmsException;
use Plugins\Sms\Domain\PhoneNumber;

/**
 * `sms:send` — send one real message, to prove the credentials work.
 *
 * This is the only thing in the plugin that proves anything end to end, because
 * no provider here has a validate-only call. It therefore COSTS MONEY and goes
 * to a real handset, so it confirms before sending and prints the segment count
 * first — a mistyped body can be three messages.
 */
final class SmsSendCommand extends AbstractCommand
{
    public function __construct(private readonly ?SmsSenderContract $sender = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'sms:send';
        $this->description = 'Send one real SMS to prove the configuration works';

        $this->addOption('to', 't', 'Destination in E.164 form, e.g. +256700000000', acceptsValue: true);
        $this->addOption('body', 'b', 'Message body', acceptsValue: true, default: 'Sent by sms:send.');
        $this->addOption('from', '', 'Override the configured sender', acceptsValue: true);
        $this->addOption('yes', 'y', 'Skip the confirmation prompt');
    }

    protected function handle(): int
    {
        if ($this->sender === null) {
            $this->error(
                'The SMS service is not bound. The project has to register this plugin in '
                . 'withModules(), and the twilio transport also needs an HttpClientPort.'
            );

            return self::FAILURE;
        }

        $to = trim((string) $this->option('to', ''));

        if ($to === '') {
            $this->error('Pass a destination with --to.');

            return self::FAILURE;
        }

        try {
            $number = PhoneNumber::parse($to);
        } catch (\InvalidArgumentException $e) {
            // Refused locally, before anything is billed — the message names
            // what is wrong with the number rather than echoing a provider code.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $body = (string) $this->option('body', '');
        $cost = $this->sender->cost($body);

        $this->section('sms:send');
        $this->info('To:       ' . $number->masked());
        $this->info('Encoding: ' . $cost->encoding->value);
        $this->info('Segments: ' . $cost->segments . ($cost->segments > 1 ? '  (billed as that many messages)' : ''));

        if ($this->sender->isDiverted($number)) {
            $this->warning('This destination is in SMS_MOCK_NUMBERS, so nothing will be sent to a provider.');
        }

        if (!$this->option('yes') && !$this->confirm('Send it?', false)) {
            $this->muted('Nothing sent.');

            return self::SUCCESS;
        }

        try {
            $result = $this->sender->send($number, $body, self::nullIfBlank($this->option('from')));
        } catch (SmsException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->success($result->describe());

        if (!$result->simulated) {
            $this->muted('The provider ACCEPTED it. Delivery is asynchronous and is not reported here —');
            $this->muted('check the handset, or the provider console.');
        }

        return self::SUCCESS;
    }

    private static function nullIfBlank(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : trim($value);
    }
}
