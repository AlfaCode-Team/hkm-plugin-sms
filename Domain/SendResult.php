<?php

declare(strict_types=1);

namespace Plugins\Sms\Domain;

/**
 * What a provider said when it accepted a message.
 *
 * "Accepted" is the strongest claim available and this type does not overstate
 * it. A provider returning 201 has taken the message, not delivered it:
 * delivery is asynchronous and reported, if at all, through a webhook this
 * plugin does not yet receive. So there is no `delivered` field here — a field
 * nothing can set truthfully is worse than no field, because a caller will read
 * it as the answer to "did it arrive?".
 */
final readonly class SendResult
{
    public function __construct(
        /** The transport that accepted it, e.g. 'twilio'. */
        public string $transport,
        /** The provider's own id for the message, when it gives one. */
        public ?string $providerId,
        /** Segments the provider will bill, as computed locally. */
        public int $segments,
        public Encoding $encoding,
        /** True when nothing was actually sent (mock/log transports). */
        public bool $simulated = false,
    ) {}

    public function describe(): string
    {
        return sprintf(
            '%s%s accepted %d segment%s (%s)%s',
            $this->transport,
            $this->providerId === null ? '' : " [{$this->providerId}]",
            $this->segments,
            $this->segments === 1 ? '' : 's',
            $this->encoding->value,
            $this->simulated ? ' — SIMULATED, nothing was sent' : '',
        );
    }
}
