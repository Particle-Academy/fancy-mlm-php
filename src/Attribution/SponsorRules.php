<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

use Closure;
use FancyMlm\Attribution\Exceptions\SelfReferral;
use FancyMlm\Attribution\Exceptions\SponsorCycle;
use FancyMlm\Attribution\Exceptions\SponsorImmutable;

/**
 * The rules every sponsor assignment obeys, whoever makes it.
 */
final class SponsorRules
{
    /**
     * Throw unless `$sponsorId` may become `$memberId`'s sponsor.
     *
     * @param Closure(string):?string $sponsorOf
     * @return bool false when the member already has exactly this sponsor (nothing to do)
     */
    public static function assertAssignable(string $memberId, string $sponsorId, ?string $current, Closure $sponsorOf): bool
    {
        if ($current !== null) {
            if ($current === $sponsorId) {
                return false;
            }
            throw SponsorImmutable::for($memberId, $current, $sponsorId);
        }
        if ($memberId === $sponsorId) {
            throw SelfReferral::for($memberId);
        }
        if (self::wouldCycle($memberId, $sponsorId, $sponsorOf)) {
            throw SponsorCycle::for($memberId, $sponsorId);
        }

        return true;
    }

    /**
     * True when `$memberId` is an ancestor of `$sponsorId` (or the same member),
     * i.e. sponsoring would close a loop. Terminates on a pre-existing loop
     * that does not involve the member.
     *
     * @param Closure(string):?string $sponsorOf
     */
    public static function wouldCycle(string $memberId, string $sponsorId, Closure $sponsorOf): bool
    {
        $seen = [];
        $cursor = $sponsorId;
        while ($cursor !== null && ! isset($seen[$cursor])) {
            if ($cursor === $memberId) {
                return true;
            }
            $seen[$cursor] = true;
            $cursor = $sponsorOf($cursor);
        }

        return false;
    }
}
