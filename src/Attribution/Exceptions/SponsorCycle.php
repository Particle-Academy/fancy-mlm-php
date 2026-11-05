<?php

declare(strict_types=1);

namespace FancyMlm\Attribution\Exceptions;

final class SponsorCycle extends AttributionException
{
    public static function for(string $memberId, string $sponsorId): self
    {
        return new self("fancy-mlm: {$sponsorId} is already in {$memberId}'s downline; sponsoring would create a cycle.");
    }
}
