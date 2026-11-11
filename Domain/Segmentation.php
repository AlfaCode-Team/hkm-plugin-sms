<?php

declare(strict_types=1);

namespace Plugins\Sms\Domain;

/**
 * What a message body will actually cost to send.
 *
 * WHY THIS EXISTS AT ALL
 * ----------------------
 * An SMS is billed per SEGMENT, not per message, and nothing in the send path
 * tells you how many segments you just bought. The rules are not intuitive:
 *
 *   - One character outside the GSM 7-bit alphabet re-encodes the WHOLE message
 *     to UCS-2, which drops capacity from 160 characters to 70. A single emoji,
 *     or a curly apostrophe pasted in from a word processor, turns a one-segment
 *     notification into three.
 *   - Nine characters that look ordinary — | ^ € { } [ ] ~ \ — are in the GSM
 *     EXTENSION table and cost TWO septets each, so a body of 160 of them is two
 *     segments while 160 letters is one.
 *   - Concatenation is not free: a 161-character GSM-7 body is not 160 + 1, it is
 *     two segments of 153, because each segment spends 6 octets on a header.
 *
 * The practical failure this prevents is a templated OTP or alert that costs
 * three times what anyone budgeted, discovered on an invoice, and the quieter
 * one where a provider truncates at a segment boundary and the code at the end
 * of the message never arrives.
 *
 * Reference: 3GPP TS 23.038, the GSM 7-bit default alphabet and its extension
 * table. The tables below are transcribed from it rather than inferred.
 */
final readonly class Segmentation
{
    /**
     * The GSM 7-bit default alphabet, every character costing one septet.
     *
     * Transcribed in code-point order 0x00–0x7F. CR and LF are members; ESC
     * (0x1B) is not, because it only ever introduces an extension-table
     * character and is accounted for there.
     */
    private const GSM7_BASIC = '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'Åå'
        . 'Δ_ΦΓΛΩΠΨΣΘΞ'
        . 'ÆæßÉ'
        . ' !"#¤%&\'()*+,-./'
        . '0123456789:;<=>?'
        . '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§'
        . '¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /**
     * The GSM 7-bit extension table. Each of these is sent as ESC + the
     * character, so each costs TWO septets.
     *
     * The euro sign is the one people hit by accident; the brackets and braces
     * are the ones that hit anything sending JSON-ish or code-ish text.
     */
    private const GSM7_EXTENDED = '|^€{}[]~\\' . "\f";

    private function __construct(
        public Encoding $encoding,
        /** Septets for GSM-7, UTF-16 code units for UCS-2. */
        public int $length,
        public int $segments,
    ) {}

    public static function of(string $body): self
    {
        $characters = self::characters($body);

        $septets = 0;
        $gsm7    = true;

        foreach ($characters as $character) {
            if (self::contains(self::GSM7_BASIC, $character)) {
                $septets += 1;
                continue;
            }

            if (self::contains(self::GSM7_EXTENDED, $character)) {
                $septets += 2;
                continue;
            }

            $gsm7 = false;
            break;
        }

        if ($gsm7) {
            return new self(Encoding::Gsm7, $septets, self::segmentsFor(Encoding::Gsm7, $septets));
        }

        // UCS-2 is billed in 16-bit code units, so anything outside the Basic
        // Multilingual Plane — every emoji, and a good deal of CJK extension —
        // counts as TWO. Counting "characters" here instead of code units is the
        // standard way to under-report an emoji-bearing message by half.
        $units = 0;
        foreach ($characters as $character) {
            $units += self::codeUnits($character);
        }

        return new self(Encoding::Ucs2, $units, self::segmentsFor(Encoding::Ucs2, $units));
    }

    /** True when sending this body costs more than one segment. */
    public function isConcatenated(): bool
    {
        return $this->segments > 1;
    }

    private static function segmentsFor(Encoding $encoding, int $length): int
    {
        if ($length === 0) {
            // An empty body is still one message to a provider, and every
            // provider this plugin talks to rejects it. Reporting 0 segments
            // would make an impossible send look free.
            return 1;
        }

        if ($length <= $encoding->singleSegmentCapacity()) {
            return 1;
        }

        return (int) ceil($length / $encoding->concatenatedSegmentCapacity());
    }

    /**
     * Split a UTF-8 string into characters, without assuming mbstring.
     *
     * @return list<string>
     */
    private static function characters(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $characters = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY);

        if ($characters === false) {
            // Invalid UTF-8 cannot be segmented meaningfully, and guessing a
            // number here would be worse than refusing: the caller would act on
            // a cost that does not correspond to any real message.
            throw new \InvalidArgumentException('An SMS body must be valid UTF-8.');
        }

        return $characters;
    }

    /** How many UTF-16 code units one character occupies. */
    private static function codeUnits(string $character): int
    {
        $codepoint = self::codepoint($character);

        // Above U+FFFF a character is a surrogate PAIR: two code units, four
        // bytes on the wire. This is every emoji.
        return $codepoint > 0xFFFF ? 2 : 1;
    }

    private static function codepoint(string $character): int
    {
        // unpack() over the UTF-32 form avoids depending on mbstring or intl for
        // what is a four-byte read.
        $utf32 = @iconv('UTF-8', 'UTF-32BE', $character);

        if (!\is_string($utf32) || \strlen($utf32) < 4) {
            return 0;
        }

        /** @var array{1: int}|false $parsed */
        $parsed = unpack('N', substr($utf32, 0, 4));

        return $parsed === false ? 0 : $parsed[1];
    }

    /** Multibyte-safe needle test over a known-UTF-8 haystack. */
    private static function contains(string $haystack, string $character): bool
    {
        return str_contains($haystack, $character);
    }
}
