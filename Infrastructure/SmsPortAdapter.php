<?php

declare(strict_types=1);

namespace Plugins\Sms\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SmsPort;
use Plugins\Sms\API\Contracts\SmsSenderContract;

/**
 * Binds the kernel's SmsPort onto this plugin's service.
 *
 * The port is `send(string $to, string $message): void` and nothing more. That
 * narrowness is the point: a repository or service typed against it cannot come
 * to depend on a provider id or a segment count, so it stays portable across
 * any future adapter.
 *
 * The void return is not a claim that the message arrived. It means the
 * provider accepted it; a failure raises SmsException (a GatewayException), and
 * exception-or-nothing is the only error channel a void signature has.
 */
final readonly class SmsPortAdapter implements SmsPort
{
    public function __construct(private SmsSenderContract $sender) {}

    public function send(string $to, string $message): void
    {
        // The SendResult is deliberately dropped. Code that needs it should
        // depend on SmsSenderContract instead — widening the kernel port to
        // return one would change a contract shared by every future adapter in
        // order to serve callers who can already reach the richer one.
        $this->sender->send($to, $message);
    }
}
