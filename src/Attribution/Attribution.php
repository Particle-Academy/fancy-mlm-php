<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

use DateTimeImmutable;

/** The record of which scan made a member's sponsor. One per member, ever. */
final class Attribution
{
    public function __construct(
        public readonly string $memberId,
        public readonly string $sponsorMemberId,
        public readonly string $code,
        public readonly string $scope,
        public readonly ?string $scanId,
        public readonly string $model,
        public readonly DateTimeImmutable $attributedAt,
    ) {}
}
