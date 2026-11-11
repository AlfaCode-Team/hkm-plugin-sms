<?php

declare(strict_types=1);

namespace Plugins\Sms\Infrastructure\Config;

use Plugins\Sms\Domain\PhoneNumber;

/**
 * Everything this plugin reads from the environment, read ONCE.
 *
 * Every key here is declared in module.json `config[]`, which is not optional:
 * the boot fails on an env var a module reads but does not declare.
 */
final readonly class SmsConfig
{
    /** @var array<string, string> E.164 => the fixed code an OTP flow should use for it */
    public array $mockNumbers;

    /**
     * @param array<string, string> $mockNumbers keyed by phone number in ANY
     *                                           format; normalised to E.164 here
     */
    private function __construct(
        public string $transport,
        public ?string $from,
        public ?int $maxSegments,
        public int $timeout,
        array $mockNumbers,
        public ?string $twilioAccountSid,
        public ?string $twilioAuthToken,
        public ?string $twilioFrom,
        public ?string $twilioMessagingServiceSid,
    ) {
        // NORMALISED HERE, not in the factories, because this is the only place
        // every path goes through. A caught bug: of() used to take the array
        // verbatim, so a project or test wiring '00256 700 000 000' by hand got
        // a key that isMock() — which looks up the E.164 form — could never
        // match. The destination was then NOT diverted and a message meant to
        // be swallowed went to a real handset. The dangerous direction.
        $this->mockNumbers = self::normalise($mockNumbers);
    }

    public static function fromEnv(): self
    {
        // 'log' and not 'twilio'. A plugin that defaulted to a live provider
        // would need credentials to boot at all, and the first thing a missing
        // credential does is fail a request that was working before the plugin
        // was installed.
        $transport = strtolower(trim((string) env('SMS_TRANSPORT', 'log')));

        $max = (int) env('SMS_MAX_SEGMENTS', 0);

        return new self(
            transport:    $transport === '' ? 'log' : $transport,
            from:         self::stringOrNull(env('SMS_FROM')),
            // 0 means "no ceiling", because an int-typed config var cannot be
            // absent — ValidateConfigStage reads '' as missing and a default of
            // 0 is the only way to say "unset" in that type.
            maxSegments:  $max > 0 ? $max : null,
            timeout:      max(1, (int) env('SMS_TIMEOUT', 15)),
            mockNumbers:  self::parseMockNumbers(self::stringOrNull(env('SMS_MOCK_NUMBERS'))),
            twilioAccountSid:          self::stringOrNull(env('SMS_TWILIO_ACCOUNT_SID')),
            twilioAuthToken:           self::stringOrNull(env('SMS_TWILIO_AUTH_TOKEN')),
            twilioFrom:                self::stringOrNull(env('SMS_TWILIO_FROM')),
            twilioMessagingServiceSid: self::stringOrNull(env('SMS_TWILIO_MESSAGING_SERVICE_SID')),
        );
    }

    /**
     * Build one directly, for tests and for a project wiring this by hand.
     *
     * @param array<string, string> $mockNumbers
     */
    public static function of(
        string $transport = 'log',
        ?string $from = null,
        ?int $maxSegments = null,
        int $timeout = 15,
        array $mockNumbers = [],
        ?string $twilioAccountSid = null,
        ?string $twilioAuthToken = null,
        ?string $twilioFrom = null,
        ?string $twilioMessagingServiceSid = null,
    ): self {
        return new self(
            $transport,
            $from,
            $maxSegments,
            $timeout,
            $mockNumbers,
            $twilioAccountSid,
            $twilioAuthToken,
            $twilioFrom,
            $twilioMessagingServiceSid,
        );
    }

    /** True when this destination must never reach a real provider. */
    public function isMock(PhoneNumber $number): bool
    {
        return \array_key_exists($number->e164, $this->mockNumbers);
    }

    /**
     * The fixed code configured for a mock destination, if any.
     *
     * An OTP flow uses this INSTEAD of generating a code, so a test or a
     * reviewer can sign in as that number without an SMS being sent. It lives
     * here rather than in the auth plugin because the number-to-code mapping is
     * operator configuration about SMS, and two plugins reading the same env
     * var is how they stop agreeing about it.
     */
    public function mockCodeFor(PhoneNumber $number): ?string
    {
        $code = $this->mockNumbers[$number->e164] ?? null;

        return $code === '' ? null : $code;
    }

    /**
     * `+256700000000:123456,+14155550100:000000`
     *
     * A malformed entry is SKIPPED rather than fatal. This is a development
     * convenience, and taking a production boot down over a stray comma in it
     * would be a worse trade than ignoring the entry — `sms:status` prints what
     * was actually parsed, so a typo is visible where it matters.
     *
     * @return array<string, string>
     */
    private static function parseMockNumbers(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $parsed = [];

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            // Split on the LAST colon so a number written with one is not
            // mangled; the code never contains a colon.
            $at     = strrpos($entry, ':');
            $number = $at === false ? $entry : substr($entry, 0, $at);
            $code   = $at === false ? '' : substr($entry, $at + 1);

            if (!PhoneNumber::isValid($number)) {
                continue;
            }

            $parsed[PhoneNumber::parse($number)->e164] = trim($code);
        }

        return $parsed;
    }

    /**
     * Re-key a mock-number map by E.164, dropping entries that are not numbers.
     *
     * Dropping rather than throwing, for the same reason parseMockNumbers()
     * does: this is a development convenience and a typo in it must not take a
     * boot down. `sms:status` prints the count and the masked numbers that were
     * actually parsed, so a dropped entry is visible where it matters.
     *
     * @param array<string, string> $numbers
     * @return array<string, string>
     */
    private static function normalise(array $numbers): array
    {
        $normalised = [];

        foreach ($numbers as $number => $code) {
            if (!PhoneNumber::isValid((string) $number)) {
                continue;
            }

            $normalised[PhoneNumber::parse((string) $number)->e164] = trim($code);
        }

        return $normalised;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return $value === null ? null : (string) $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
