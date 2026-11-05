<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

use DateTimeImmutable;
use FancyMlm\Attribution\Exceptions\InvalidInput;

/**
 * One audited sponsor correction: who changed which member's sponsor, from
 * what, to what, when and why. Append-only: the package writes these and never
 * updates or deletes one, and the database refuses both where the guard is
 * installed.
 */
final class SponsorCorrection
{
    /** A reason is at most this many UTF-8 bytes, after trimming. */
    public const REASON_MAX_BYTES = 1000;

    /** An actor id is at most this many UTF-8 bytes (the indexed column width). */
    public const ACTOR_MAX_BYTES = 191;

    public function __construct(
        public readonly ?string $id,
        public readonly string $memberId,
        public readonly string $fromSponsorId,
        public readonly string $toSponsorId,
        public readonly string $reason,
        public readonly string $actorId,
        public readonly DateTimeImmutable $correctedAt,
    ) {}

    public function withId(string $id): self
    {
        return new self($id, $this->memberId, $this->fromSponsorId, $this->toSponsorId, $this->reason, $this->actorId, $this->correctedAt);
    }

    /**
     * The reason as stored: trimmed with PHP trim() (ASCII whitespace and NUL
     * only), non-empty, valid UTF-8 and at most {@see REASON_MAX_BYTES} bytes.
     */
    public static function normalizeReason(string $reason): string
    {
        $trimmed = trim($reason);
        if ($trimmed === '') {
            throw new InvalidInput('reason', 'A sponsor correction needs a reason.');
        }
        if (! self::isUtf8($trimmed)) {
            throw new InvalidInput('reason', 'The reason must be valid UTF-8.');
        }
        if (strlen($trimmed) > self::REASON_MAX_BYTES) {
            throw new InvalidInput('reason', 'The reason is at most '.self::REASON_MAX_BYTES.' bytes.');
        }

        return $trimmed;
    }

    /** The actor id is stored as given: non-empty after trimming, valid UTF-8, at most {@see ACTOR_MAX_BYTES} bytes. */
    public static function assertActor(string $actorId): void
    {
        if (trim($actorId) === '') {
            throw new InvalidInput('actorId', 'A sponsor correction names the actor who made it.');
        }
        if (! self::isUtf8($actorId)) {
            throw new InvalidInput('actorId', 'The actor id must be valid UTF-8.');
        }
        if (strlen($actorId) > self::ACTOR_MAX_BYTES) {
            throw new InvalidInput('actorId', 'The actor id is at most '.self::ACTOR_MAX_BYTES.' bytes.');
        }
    }

    /** An empty pattern with the u modifier matches only valid UTF-8; nothing to backtrack on. */
    private static function isUtf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }
}
