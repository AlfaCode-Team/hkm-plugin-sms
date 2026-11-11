<?php

declare(strict_types=1);

namespace Plugins\Sms\Infrastructure\Transport;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use Plugins\Sms\Domain\Contracts\SmsTransport;
use Plugins\Sms\Domain\SendResult;
use Plugins\Sms\Domain\SmsMessage;

/**
 * Writes the message to the log instead of sending it. For development.
 *
 * THE BODY IS LOGGED IN FULL, AND THAT IS THE POINT: a developer working on an
 * OTP flow needs to read the code. It is also exactly why this transport must
 * never be the default and why every result it returns is marked `simulated`
 * — a caller that reports "sent" on the back of this one is reporting a
 * message that went to a log file.
 *
 * The destination is still MASKED. The body is the developer's own test data;
 * the number may belong to a real person, and a log is where personal data
 * leaks from.
 */
final readonly class LogTransport implements SmsTransport
{
    public function __construct(private ?LoggerPort $logger = null) {}

    public function name(): string
    {
        return 'log';
    }

    public function send(SmsMessage $message): SendResult
    {
        $line = sprintf('[sms] %s — %s', $message->describe(), $message->body);

        if ($this->logger !== null) {
            $this->logger->info('sms.simulated', [
                'to'       => $message->to->masked(),
                'body'     => $message->body,
                'segments' => $message->segmentation->segments,
                'encoding' => $message->segmentation->encoding->value,
            ]);
        } else {
            // No logger bound: still say something, because a silent
            // development transport looks exactly like a broken one.
            error_log($line);
        }

        return new SendResult(
            transport:  $this->name(),
            providerId: null,
            segments:   $message->segmentation->segments,
            encoding:   $message->segmentation->encoding,
            simulated:  true,
        );
    }

    public function describe(): string
    {
        return 'log (nothing is sent; bodies are written to the application log)';
    }
}
