<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

use DateTimeImmutable;

/**
 * One recorded scan or click of a referral code.
 *
 * Carries the keyed session hash, never the session id, and never an IP or a
 * user agent. `$context` holds only what the host passed explicitly.
 */
final class Scan
{
    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly ?string $id,
        public readonly string $code,
        public readonly string $memberId,
        public readonly string $scope,
        public readonly string $sessionHash,
        public readonly DateTimeImmutable $scannedAt,
        public readonly array $context = [],
    ) {}

    public function withId(string $id): self
    {
        return new self($id, $this->code, $this->memberId, $this->scope, $this->sessionHash, $this->scannedAt, $this->context);
    }
}
