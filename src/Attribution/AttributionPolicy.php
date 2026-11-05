<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

use FancyMlm\Attribution\Exceptions\InvalidInput;

/**
 * How a signup is matched to a scan.
 *
 * Defaults to FIRST touch over a 30-day window. The sponsor is permanent, so
 * the model decides who owns a member for good: first touch credits the person
 * who actually introduced the prospect, a later link cannot snipe an
 * introduction someone else made, and the answer for a session does not move as
 * more scans arrive. Last touch is there for hosts that want it.
 */
final class AttributionPolicy
{
    public const FIRST_TOUCH = 'first_touch';

    public const LAST_TOUCH = 'last_touch';

    public function __construct(
        public readonly string $model = self::FIRST_TOUCH,
        public readonly int $windowSeconds = 30 * 86400,
        public readonly int $dedupeSeconds = 1800,
    ) {
        if ($model !== self::FIRST_TOUCH && $model !== self::LAST_TOUCH) {
            throw new InvalidInput('model', "Attribution model must be first_touch or last_touch, not {$model}.");
        }
        if ($windowSeconds <= 0) {
            throw new InvalidInput('windowSeconds', 'The attribution window must be positive.');
        }
        if ($dedupeSeconds < 0) {
            throw new InvalidInput('dedupeSeconds', 'The dedupe window cannot be negative.');
        }
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['model'] ?? self::FIRST_TOUCH),
            (int) ($data['windowSeconds'] ?? 30 * 86400),
            (int) ($data['dedupeSeconds'] ?? 1800),
        );
    }
}
