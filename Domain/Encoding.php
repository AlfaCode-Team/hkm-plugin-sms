<?php

declare(strict_types=1);

namespace Plugins\Sms\Domain;

/**
 * How a message body will be encoded on the wire, which decides how much of it
 * fits and therefore what it costs.
 *
 * There is no third option in practice. A carrier either sends the body in the
 * GSM 7-bit default alphabet (3GPP TS 23.038) or escalates the whole message to
 * UCS-2, and the escalation is all-or-nothing: ONE character outside the GSM
 * alphabet re-encodes every character in the message.
 */
enum Encoding: string
{
    case Gsm7 = 'GSM-7';
    case Ucs2 = 'UCS-2';

    /** Characters per segment when the message fits in a single segment. */
    public function singleSegmentCapacity(): int
    {
        return match ($this) {
            // 140 octets. 140 * 8 / 7 = 160 septets.
            self::Gsm7 => 160,
            // 140 octets / 2 bytes per code unit.
            self::Ucs2 => 70,
        };
    }

    /**
     * Characters per segment once the message is concatenated.
     *
     * Lower than the single-segment capacity because a concatenated message
     * spends 6 octets of each segment on a User Data Header carrying the
     * reference, part number and total. That is why a 161-character GSM-7
     * message is not "160 + 1" but two segments of 153.
     */
    public function concatenatedSegmentCapacity(): int
    {
        return match ($this) {
            // 134 octets * 8 / 7 = 153 septets.
            self::Gsm7 => 153,
            // 134 octets / 2, rounded down.
            self::Ucs2 => 67,
        };
    }
}
