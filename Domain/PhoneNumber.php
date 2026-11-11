<?php

declare(strict_types=1);

namespace Plugins\Sms\Domain;

/**
 * A destination in E.164 form, which is the only form every provider in this
 * plugin accepts.
 *
 * WHY THIS IS A TYPE AND NOT A STRING
 * -----------------------------------
 * A malformed number does not fail loudly. Providers accept the request, queue
 * the message, bill for it, and report the failure asynchronously — so the send
 * path returns success and the SMS never arrives. Validating at construction
 * turns that into a refusal at the call site, where the caller still has the
 * context to fix it.
 *
 * This deliberately does NOT try to know which numbers exist. E.164 constrains
 * the SHAPE — a leading country code and up to 15 digits total — and nothing
 * here can tell an unassigned range from an assigned one. Claiming otherwise
 * would mean rejecting valid numbers from countries whose numbering plans this
 * code does not track, which is a worse failure than passing one along.
 */
final readonly class PhoneNumber
{
    /** E.164 allows at most 15 digits including the country code. */
    public const MAX_DIGITS = 15;

    /**
     * The shortest plausible international number. Not a standard: the ITU sets
     * no global minimum, and national plans vary. Eight is low enough to admit
     * every assigned range the author could find and high enough to catch a
     * truncated input, which is the actual mistake being guarded against.
     */
    public const MIN_DIGITS = 8;

    private function __construct(public string $e164) {}

    /**
     * @throws \InvalidArgumentException when the value cannot be read as E.164
     */
    public static function parse(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new \InvalidArgumentException('A phone number cannot be empty.');
        }

        // Accept the shapes people and spreadsheets actually produce — spaces,
        // hyphens, dots, parentheses, a non-breaking space pasted from a web
        // page — and normalise them away. Rejecting "+256 700 000 000" would
        // make this type useless for any input a human touched.
        $digits = preg_replace('/[\s\-.()\x{00A0}]/u', '', $trimmed) ?? '';

        // A leading 00 is the international prefix in most of the world and
        // means exactly what + means.
        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        }

        if (!str_starts_with($digits, '+')) {
            // A number with no country code is ambiguous, and GUESSING one is
            // how a message goes to the right digits in the wrong country.
            // There is no default here on purpose.
            throw new \InvalidArgumentException(
                "[{$value}] has no country code. A destination must be in E.164 form, "
                . 'starting with + (or 00), because a bare national number is '
                . 'ambiguous and this plugin will not guess a country for it.'
            );
        }

        $body = substr($digits, 1);

        if (!preg_match('/^[0-9]+$/', $body)) {
            throw new \InvalidArgumentException(
                "[{$value}] contains characters that are not digits once formatting is removed."
            );
        }

        if (str_starts_with($body, '0')) {
            // +0… is not a valid country code; this is almost always a national
            // trunk prefix left in front of the number after a careless
            // concatenation ("+" . "0700000000").
            throw new \InvalidArgumentException(
                "[{$value}] starts with a zero after the +, which is not a country code. "
                . 'A national trunk prefix is dropped in E.164.'
            );
        }

        $length = \strlen($body);

        if ($length > self::MAX_DIGITS) {
            throw new \InvalidArgumentException(
                "[{$value}] has {$length} digits; E.164 allows at most " . self::MAX_DIGITS . '.'
            );
        }

        if ($length < self::MIN_DIGITS) {
            throw new \InvalidArgumentException(
                "[{$value}] has {$length} digits, which is too few to be an international number. "
                . 'A truncated or national-format number is the usual cause.'
            );
        }

        return new self('+' . $body);
    }

    /** True when the value parses, for a validator that must not throw. */
    public static function isValid(string $value): bool
    {
        try {
            self::parse($value);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * The number with everything but the last two digits masked.
     *
     * A phone number is personal data and a log is the place it leaks from. Any
     * diagnostic this plugin writes uses this, never $e164 — see SmsException
     * and the transports.
     */
    public function masked(): string
    {
        $body = substr($this->e164, 1);
        $tail = substr($body, -2);

        return '+' . str_repeat('*', max(0, \strlen($body) - 2)) . $tail;
    }

    public function __toString(): string
    {
        return $this->e164;
    }
}
