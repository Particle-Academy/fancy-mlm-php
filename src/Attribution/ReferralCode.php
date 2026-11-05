<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

use DateTimeImmutable;

/**
 * A referral code owned by one member, in one host-defined scope. A member can
 * hold many codes across scopes. The URL a code travels in is the host's.
 */
final class ReferralCode
{
    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    public function __construct(
        public readonly string $code,
        public readonly string $memberId,
        public readonly string $scope,
        public readonly string $status = self::ACTIVE,
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly ?DateTimeImmutable $createdAt = null,
    ) {}

    /** Active, and either no expiry or `$at` strictly before it. */
    public function usableAt(DateTimeImmutable $at): bool
    {
        return $this->status === self::ACTIVE
            && ($this->expiresAt === null || $at < $this->expiresAt);
    }
}
