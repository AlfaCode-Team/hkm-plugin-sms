<?php

declare(strict_types=1);

namespace Tests\Plugins\Sms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Sms\Domain\Encoding;
use Plugins\Sms\Domain\Segmentation;

/**
 * The GSM 7-bit / UCS-2 segment arithmetic, against 3GPP TS 23.038.
 *
 * These are the numbers an invoice is computed from, so they are asserted as
 * exact values rather than ranges. The boundary cases are the whole point: 160
 * vs 161 and 306 vs 307 are where the concatenation header starts costing
 * capacity, and getting that wrong under-reports every long message by one
 * segment.
 */
#[CoversClass(Segmentation::class)]
#[CoversClass(Encoding::class)]
final class SegmentationTest extends TestCase
{
    /** @return list<array{string, string, int, int}> */
    public static function bodies(): array
    {
        return [
            // body, encoding, length, segments
            ['Hello', 'GSM-7', 5, 1],
            ['Your code is 123456', 'GSM-7', 19, 1],

            // GSM-7 boundaries. 160 fits one segment; 161 does not fit "160 + 1"
            // because concatenation spends 7 septets per segment on a header,
            // so the capacity drops to 153.
            [str_repeat('a', 160), 'GSM-7', 160, 1],
            [str_repeat('a', 161), 'GSM-7', 161, 2],
            [str_repeat('a', 306), 'GSM-7', 306, 2],
            [str_repeat('a', 307), 'GSM-7', 307, 3],

            // Extension-table characters cost TWO septets each, so eighty of
            // them exactly fill a single segment and eighty-one overflow it.
            ['€', 'GSM-7', 2, 1],
            [str_repeat('€', 80), 'GSM-7', 160, 1],
            [str_repeat('€', 81), 'GSM-7', 162, 2],
            ['{}[]~\\|^', 'GSM-7', 16, 1],

            // One non-GSM character re-encodes the WHOLE message, dropping
            // capacity from 160 to 70.
            ['Привет', 'UCS-2', 6, 1],
            [str_repeat('ж', 70), 'UCS-2', 70, 1],
            [str_repeat('ж', 71), 'UCS-2', 71, 2],

            // Outside the BMP a character is a surrogate PAIR: two code units.
            // Counting "characters" here is the standard way to under-report an
            // emoji-bearing message by half.
            ['Hi 😀', 'UCS-2', 5, 1],
            [str_repeat('😀', 35), 'UCS-2', 70, 1],
            [str_repeat('😀', 36), 'UCS-2', 72, 2],
        ];
    }

    #[DataProvider('bodies')]
    public function test_segmentation(string $body, string $encoding, int $length, int $segments): void
    {
        $result = Segmentation::of($body);

        self::assertSame($encoding, $result->encoding->value, 'encoding');
        self::assertSame($length, $result->length, 'length');
        self::assertSame($segments, $result->segments, 'segments');
    }

    public function test_a_curly_apostrophe_escalates_an_otherwise_plain_message(): void
    {
        // The realistic failure. This body reads as plain English and a word
        // processor or a phone keyboard produces U+2019 rather than U+0027, so
        // a template that looks fine is billed at UCS-2 capacity.
        $plain = Segmentation::of("Your code is 123456. Don't share it.");
        $curly = Segmentation::of("Your code is 123456. Don\u{2019}t share it.");

        self::assertSame(Encoding::Gsm7, $plain->encoding);
        self::assertSame(Encoding::Ucs2, $curly->encoding);

        // Same visible text, half the capacity.
        self::assertSame(160, $plain->encoding->singleSegmentCapacity());
        self::assertSame(70, $curly->encoding->singleSegmentCapacity());
    }

    public function test_a_non_breaking_space_escalates_too(): void
    {
        // U+00A0 is not in the GSM alphabet, and it is invisible. It arrives
        // from pasted web copy constantly.
        self::assertSame(Encoding::Ucs2, Segmentation::of("Code:\u{00A0}123456")->encoding);
    }

    public function test_newlines_and_carriage_returns_stay_in_gsm7(): void
    {
        // Both are members of the default alphabet at 0x0A and 0x0D. Treating
        // them as non-GSM would escalate every multi-line message.
        $result = Segmentation::of("Line one\r\nLine two");

        self::assertSame(Encoding::Gsm7, $result->encoding);
        self::assertSame(18, $result->length);
    }

    public function test_the_full_gsm7_basic_alphabet_stays_gsm7(): void
    {
        // Guards the transcription itself. If a character were missing from the
        // table, this escalates to UCS-2 and the test says so.
        $alphabet = '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ'
            . ' !"#¤%&\'()*+,-./0123456789:;<=>?'
            . '¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§'
            . '¿abcdefghijklmnopqrstuvwxyzäöñüà';

        $result = Segmentation::of($alphabet);

        self::assertSame(Encoding::Gsm7, $result->encoding);

        // Every character is one septet, so the length equals the character
        // count — which also proves none of them fell through to the extension
        // table and silently cost double.
        self::assertSame(
            \count(preg_split('//u', $alphabet, -1, PREG_SPLIT_NO_EMPTY) ?: []),
            $result->length,
        );
    }

    public function test_an_empty_body_is_one_segment_not_zero(): void
    {
        $result = Segmentation::of('');

        self::assertSame(0, $result->length);
        // Reporting 0 would make an impossible send look free. Every provider
        // here rejects an empty body, and SmsMessage refuses it earlier.
        self::assertSame(1, $result->segments);
    }

    public function test_is_concatenated(): void
    {
        self::assertFalse(Segmentation::of(str_repeat('a', 160))->isConcatenated());
        self::assertTrue(Segmentation::of(str_repeat('a', 161))->isConcatenated());
    }

    public function test_invalid_utf8_is_refused_rather_than_guessed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // A cost computed from an unsegmentable body would not correspond to any
        // real message, and the caller would act on it.
        Segmentation::of("\xC3\x28");
    }
}
