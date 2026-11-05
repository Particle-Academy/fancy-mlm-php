<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

/**
 * What a correction asks for, as handed to the host's authorizer. The reason
 * is already trimmed and validated. The authorizer runs before the package
 * checks that either member exists, so it learns nothing it was not given.
 */
final class SponsorCorrectionRequest
{
    public function __construct(
        public readonly string $memberId,
        public readonly string $toSponsorId,
        public readonly string $reason,
        public readonly string $actorId,
    ) {}
}
