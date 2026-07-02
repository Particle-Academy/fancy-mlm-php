<?php

declare(strict_types=1);

namespace FancyMlm\Tree;

use FancyMlm\Contracts\MemberRepository;
use FancyMlm\Member;
use FancyMlm\Plan\CompensationPlan;
use FancyMlm\Referral\RewardComputation;

/**
 * Shared upward-walk for every tree: climb this tree's parent pointer from the
 * origin, pay each active upline member `base × levelFactor(level) ×
 * tierMultiplier(tier)` up to the plan's depth, with dynamic compression and a
 * cycle guard. Subclasses only pick the parent pointer + frontline cap.
 */
abstract class UpwardTree implements TreeStrategy
{
    /** The parent this tree climbs (sponsor vs placement). */
    abstract protected function parentId(Member $member): ?string;

    public function frontlineCap(CompensationPlan $plan): int
    {
        return 0; // unlimited by default
    }

    public function distribute(
        Member $origin,
        float $baseAmount,
        CompensationPlan $plan,
        MemberRepository $members,
        array $context,
    ): array {
        $rewards = [];
        $maxLevels = $plan->levels();
        $visited = [$origin->id => true];
        $currentId = $this->parentId($origin);
        $level = 0;

        while ($currentId !== null && $level < $maxLevels) {
            if (isset($visited[$currentId])) {
                break; // cyclic chain — stop rather than loop forever
            }
            $visited[$currentId] = true;

            $upline = $members->find($currentId);
            if ($upline === null) {
                break;
            }

            if (! $upline->active) {
                if ($plan->compression) {
                    $currentId = $this->parentId($upline); // skip without consuming a level
                    continue;
                }
                break; // no compression: an inactive member blocks the chain
            }

            $level++;
            $factor = $plan->levelFactor($level);
            $multiplier = $plan->tierMultiplier($upline->tier);
            $amount = $baseAmount * $factor * $multiplier;

            if ($amount > 0.0) {
                $rewards[] = new RewardComputation(
                    originMemberId: $origin->id,
                    recipientMemberId: $upline->id,
                    level: $level,
                    metric: $plan->metric,
                    baseAmount: $baseAmount,
                    tier: $upline->tier,
                    tierMultiplier: $multiplier,
                    levelFactor: $factor,
                    amount: $amount,
                    context: $context,
                );
            }

            $currentId = $this->parentId($upline);
        }

        return $rewards;
    }
}
