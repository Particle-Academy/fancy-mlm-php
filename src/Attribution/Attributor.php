<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

use Closure;
use DateTimeImmutable;

/**
 * The pure attribution decision: given a session's scans, who sponsors the
 * member signing up? No storage, no clock. The JS twin implements the same
 * function and both run the shared fixture.
 *
 * - A scan counts when signupAt - window <= scannedAt <= signupAt.
 * - Its code must be usable AT SIGNUP: active, and not expired. A code revoked
 *   between the scan and the signup attributes nothing.
 * - Candidates are walked in policy order (first touch: oldest first; last
 *   touch: newest first; ties keep store order). The first eligible one wins.
 * - The code's owner may not be the member (self_referral) or anyone in the
 *   member's downline (cycle).
 * - With nothing eligible, the reason is the first candidate's rejection in
 *   policy order, or no_scan when the window holds nothing.
 */
final class Attributor
{
    /**
     * @param list<array{0:Scan,1:ReferralCode}> $candidates
     * @param Closure(string):?string $sponsorOf
     */
    public static function decide(
        array $candidates,
        string $memberId,
        DateTimeImmutable $signupAt,
        AttributionPolicy $policy,
        Closure $sponsorOf,
    ): AttributionDecision {
        $earliest = $signupAt->getTimestamp() - $policy->windowSeconds;
        $signup = $signupAt->getTimestamp();

        $inWindow = array_values(array_filter(
            $candidates,
            static function (array $pair) use ($earliest, $signup): bool {
                $t = $pair[0]->scannedAt->getTimestamp();

                return $t >= $earliest && $t <= $signup;
            },
        ));

        if ($inWindow === []) {
            return AttributionDecision::none('no_scan');
        }

        // stable sort, ascending; last touch walks it backwards so ties keep "later stored wins"
        usort($inWindow, static fn (array $a, array $b): int => $a[0]->scannedAt->getTimestamp() <=> $b[0]->scannedAt->getTimestamp());
        if ($policy->model === AttributionPolicy::LAST_TOUCH) {
            $inWindow = array_reverse($inWindow);
        }

        $firstRejection = null;
        foreach ($inWindow as [$scan, $code]) {
            $rejection = self::rejection($code, $memberId, $signupAt, $sponsorOf);
            if ($rejection === null) {
                return new AttributionDecision(true, 'attributed', $code->memberId, $code->code, $code->scope, $scan->id);
            }
            $firstRejection ??= $rejection;
        }

        return AttributionDecision::none($firstRejection);
    }

    /** @param Closure(string):?string $sponsorOf */
    private static function rejection(ReferralCode $code, string $memberId, DateTimeImmutable $signupAt, Closure $sponsorOf): ?string
    {
        if (! $code->usableAt($signupAt)) {
            return 'code_inactive';
        }
        if ($code->memberId === $memberId) {
            return 'self_referral';
        }
        if (SponsorRules::wouldCycle($memberId, $code->memberId, $sponsorOf)) {
            return 'cycle';
        }

        return null;
    }
}
