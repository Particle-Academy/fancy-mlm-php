<?php

declare(strict_types=1);

namespace FancyMlm\Attribution\Exceptions;

final class SelfReferral extends AttributionException
{
    public static function for(string $memberId): self
    {
        return new self("fancy-mlm: member {$memberId} cannot sponsor themselves.");
    }
}
