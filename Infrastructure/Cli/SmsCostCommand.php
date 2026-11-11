<?php

declare(strict_types=1);

namespace Plugins\Sms\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Sms\Domain\Encoding;
use Plugins\Sms\Domain\Segmentation;

/**
 * `sms:cost` — what a body will cost, without sending it.
 *
 * Built for one specific job: checking a template before it ships. A message
 * that reads fine can cost three times what anyone expected because of a single
 * character nobody can see — a curly apostrophe from a word processor, a
 * non-breaking space, an emoji in a greeting — and nothing in the send path
 * reports that. This does, and it costs nothing to run.
 */
final class SmsCostCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'sms:cost';
        $this->description = 'Report the encoding and segment count of a message body';

        $this->addArgument('body', 'The message body to measure', required: true);
    }

    protected function handle(): int
    {
        $body = (string) $this->argument('body', '');

        if (trim($body) === '') {
            $this->error('Nothing to measure: pass the body as the argument.');

            return self::FAILURE;
        }

        $segmentation = Segmentation::of($body);

        $this->section('SMS cost');
        $this->info('Encoding: ' . $segmentation->encoding->value);
        $this->info(sprintf(
            '%s: %d of %d',
            $segmentation->encoding === Encoding::Gsm7 ? 'Septets' : 'UTF-16 code units',
            $segmentation->length,
            $segmentation->segments === 1
                ? $segmentation->encoding->singleSegmentCapacity()
                : $segmentation->segments * $segmentation->encoding->concatenatedSegmentCapacity(),
        ));

        if ($segmentation->segments === 1) {
            $this->success('1 segment');
        } else {
            $this->warning($segmentation->segments . ' segments — billed as ' . $segmentation->segments . ' messages');
        }

        if ($segmentation->encoding === Encoding::Ucs2) {
            $this->newLine();
            $this->warning(
                'This body is UCS-2, so capacity is 70 characters instead of 160. One character '
                . 'outside the GSM alphabet does that to the whole message.'
            );

            foreach ($this->nonGsmCharacters($body) as $character => $count) {
                $this->muted(sprintf(
                    '  %s  U+%04X  x%d',
                    $character,
                    $this->codepoint($character),
                    $count,
                ));
            }
        }

        return self::SUCCESS;
    }

    /**
     * The characters responsible for the escalation, so the fix is obvious.
     *
     * Naming them is the whole value: being told "this is UCS-2" leaves someone
     * staring at text that looks like plain English.
     *
     * @return array<string, int> character => how many times it appears
     */
    private function nonGsmCharacters(string $body): array
    {
        $characters = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $offenders = [];
        foreach ($characters as $character) {
            // One character at a time: Segmentation reports GSM-7 only when
            // EVERY character is in the alphabet, so a single-character probe
            // answers membership exactly.
            if (Segmentation::of($character)->encoding === Encoding::Ucs2) {
                $offenders[$character] = ($offenders[$character] ?? 0) + 1;
            }
        }

        return $offenders;
    }

    private function codepoint(string $character): int
    {
        $utf32 = @iconv('UTF-8', 'UTF-32BE', $character);

        if (!\is_string($utf32) || \strlen($utf32) < 4) {
            return 0;
        }

        /** @var array{1: int}|false $parsed */
        $parsed = unpack('N', substr($utf32, 0, 4));

        return $parsed === false ? 0 : $parsed[1];
    }
}
