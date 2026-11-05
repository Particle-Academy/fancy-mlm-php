<?php

declare(strict_types=1);

namespace FancyMlm\Attribution\Exceptions;

/**
 * The host did not authorize a sponsor correction: no authorizer is configured
 * (the default), or it returned anything other than true.
 */
final class CorrectionUnauthorized extends AttributionException
{
    public static function for(string $memberId): self
    {
        return new self("fancy-mlm: not authorized to correct the sponsor of member {$memberId}.");
    }
}
