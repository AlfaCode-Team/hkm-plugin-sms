<?php

declare(strict_types=1);

namespace Tests\Plugins\Sms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Sms\Domain\PhoneNumber;

#[CoversClass(PhoneNumber::class)]
final class PhoneNumberTest extends TestCase
{
    /** @return list<array{string, string}> */
    public static function normalised(): array
    {
        return [
            ['+256700000000', '+256700000000'],
            // The shapes humans and spreadsheets actually produce.
            ['+256 700 000 000', '+256700000000'],
            ['+256-700-000-000', '+256700000000'],
            ['+256.700.000.000', '+256700000000'],
            ['+1 (415) 555-0100', '+14155550100'],
            ['  +14155550100  ', '+14155550100'],
            // A non-breaking space, which is what pasting from a web page gives.
            ["+1\u{00A0}415\u{00A0}555\u{00A0}0100", '+14155550100'],
            // 00 is the international prefix in most of the world and means +.
            ['00256700000000', '+256700000000'],
        ];
    }

    #[DataProvider('normalised')]
    public function test_normalisation(string $input, string $expected): void
    {
        self::assertSame($expected, PhoneNumber::parse($input)->e164);
    }

    /** @return list<array{string}> */
    public static function refused(): array
    {
        return [
            [''],
            ['   '],
            // No country code: a bare national number is ambiguous, and
            // guessing a country sends the message to the right digits in the
            // wrong place.
            ['0700000000'],
            ['700000000'],
            // A trunk prefix left in front after a careless concatenation.
            ['+0700000000'],
            // Too long for E.164.
            ['+1234567890123456'],
            // Too short to be an international number.
            ['+1234567'],
            // Letters survive the formatting strip.
            ['+256ABC000000'],
            ['+256700000000x12'],
        ];
    }

    #[DataProvider('refused')]
    public function test_refusals(string $input): void
    {
        self::assertFalse(PhoneNumber::isValid($input), "[{$input}] should not parse");

        $this->expectException(\InvalidArgumentException::class);
        PhoneNumber::parse($input);
    }

    public function test_the_no_country_code_message_says_why_there_is_no_default(): void
    {
        try {
            PhoneNumber::parse('0700000000');
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            // The message has to explain the absence of a default, because
            // "invalid number" invites someone to add one.
            self::assertStringContainsString('country code', $e->getMessage());
            self::assertStringContainsString('will not guess', $e->getMessage());
        }
    }

    public function test_masking_keeps_only_the_last_two_digits(): void
    {
        // A phone number is personal data and a log is where it leaks from.
        self::assertSame('+**********00', PhoneNumber::parse('+256700000000')->masked());
        self::assertSame('+*********00', PhoneNumber::parse('+14155550100')->masked());
    }

    public function test_masking_never_reveals_the_subscriber_number(): void
    {
        $number = PhoneNumber::parse('+256772123456');
        $masked = $number->masked();

        self::assertStringNotContainsString('772123', $masked);
        self::assertSame(\strlen($number->e164), \strlen($masked));
    }

    public function test_the_boundary_lengths(): void
    {
        // E.164 allows 15 digits including the country code.
        self::assertTrue(PhoneNumber::isValid('+' . str_repeat('1', 15)));
        self::assertFalse(PhoneNumber::isValid('+' . str_repeat('1', 16)));

        self::assertTrue(PhoneNumber::isValid('+' . str_repeat('1', 8)));
        self::assertFalse(PhoneNumber::isValid('+' . str_repeat('1', 7)));
    }

    public function test_it_is_stringable_as_e164(): void
    {
        self::assertSame('+256700000000', (string) PhoneNumber::parse('+256 700 000 000'));
    }
}
