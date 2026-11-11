<?php

declare(strict_types=1);

namespace Plugins\Sms\Domain;

/**
 * One SMS, validated and costed before anything tries to send it.
 *
 * The cost is computed here rather than at the transport because every
 * transport would compute it differently or not at all, and a caller that
 * wants to refuse an accidentally-expensive message needs the number BEFORE
 * the request goes out.
 */
final readonly class SmsMessage
{
    private function __construct(
        public PhoneNumber $to,
        public string $body,
        public Segmentation $segmentation,
        /** Sender id or number; null means "whatever the transport is configured with". */
        public ?string $from,
    ) {}

    /**
     * @param int|null $maxSegments refuse a body costing more than this many
     *                              segments. Null allows any length the
     *                              provider will take.
     *
     * @throws \InvalidArgumentException on an empty body or one over the ceiling
     */
    public static function to(
        PhoneNumber|string $to,
        string $body,
        ?string $from = null,
        ?int $maxSegments = null,
    ): self {
        $number = $to instanceof PhoneNumber ? $to : PhoneNumber::parse($to);

        if (trim($body) === '') {
            throw new \InvalidArgumentException('An SMS body cannot be empty.');
        }

        $segmentation = Segmentation::of($body);

        if ($maxSegments !== null && $segmentation->segments > $maxSegments) {
            // Deliberately names the encoding, because the usual cause is one
            // invisible character — a curly apostrophe or an emoji — having
            // pushed the whole body into UCS-2 and cut its capacity to 70.
            throw new \InvalidArgumentException(sprintf(
                'This body needs %d segments (%s, %d units) but the configured ceiling is %d. '
                . 'If that is unexpected, check for a non-GSM character: one is enough to '
                . 're-encode the whole message and drop its capacity from 160 to 70.',
                $segmentation->segments,
                $segmentation->encoding->value,
                $segmentation->length,
                $maxSegments,
            ));
        }

        return new self($number, $body, $segmentation, $from);
    }

    /** A one-line description safe to log: no body, no full number. */
    public function describe(): string
    {
        return sprintf(
            '%s, %s, %d segment%s',
            $this->to->masked(),
            $this->segmentation->encoding->value,
            $this->segmentation->segments,
            $this->segmentation->segments === 1 ? '' : 's',
        );
    }
}
