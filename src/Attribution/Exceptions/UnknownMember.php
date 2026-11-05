<?php

declare(strict_types=1);

namespace FancyMlm\Attribution\Exceptions;

final class UnknownMember extends AttributionException
{
    public static function for(string $memberId): self
    {
        return new self("fancy-mlm: no member {$memberId}.");
    }
}
