<?php

declare(strict_types=1);

namespace FancyMlm\Attribution\Exceptions;

/** A member's sponsor is set once and never changed or cleared afterwards. */
final class SponsorImmutable extends AttributionException
{
    public static function for(string $memberId, ?string $current, ?string $attempted): self
    {
        return new self(sprintf(
            'fancy-mlm: sponsor_id is permanent. Member %s is sponsored by %s; refusing to change it to %s.',
            $memberId,
            $current ?? 'null',
            $attempted ?? 'null',
        ));
    }
}
