<?php

declare(strict_types=1);

namespace FancyMlm\Referral;

use FancyMlm\Contracts\MemberRepository;
use FancyMlm\Contracts\RewardSink;
use FancyMlm\Plan\CompensationPlan;

/**
 * The MVP engine: distribute a referral reward up the sponsor (enroller) tree
 * from the member who acted. For each upline level the reward is
 *
 *     amount = baseAmount × levelFactor(level) × tierMultiplier(uplineTier)
 *
 * Dynamic compression skips inactive uplines (they don't consume a level), and a
 * visited-set guards against corrupt cyclic sponsor chains. Each computed reward
 * is handed to the {@see RewardSink}; the full list is also returned.
 *
 * Reward "currency" is the sink's concern — fun-lab points (engagement) or a
 * commission ledger (monetary). Recursion guarding (an MLM-awarded reward
 * re-triggering the loop) belongs to the host listener, not here.
 */
final class ReferralEngine
{
    public function __construct(
        private readonly CompensationPlan $plan,
        private readonly MemberRepository $members,
        private readonly RewardSink $sink,
    ) {}

    /**
     * @param array<string,mixed> $context arbitrary host context (e.g. the source action)
     * @return list<RewardComputation>
     */
    public function distribute(string $originMemberId, float $baseAmount, array $context = []): array
    {
        $origin = $this->members->find($originMemberId);
        if ($origin === null || $baseAmount <= 0.0) {
            return [];
        }

        $rewards = [];
        $maxLevels = $this->plan->levels();
        $visited = [$origin->id => true];
        $currentId = $origin->sponsorId;
        $level = 0;

        while ($currentId !== null && $level < $maxLevels) {
            if (isset($visited[$currentId])) {
                break; // cyclic sponsor chain — stop rather than loop forever
            }
            $visited[$currentId] = true;

            $upline = $this->members->find($currentId);
            if ($upline === null) {
                break;
            }

            if (! $upline->active) {
                if ($this->plan->compression) {
                    $currentId = $upline->sponsorId; // skip without consuming a level
                    continue;
                }
                break; // no compression: an inactive member blocks the chain
            }

            $level++;
            $factor = $this->plan->levelFactor($level);
            $multiplier = $this->plan->tierMultiplier($upline->tier);
            $amount = $baseAmount * $factor * $multiplier;

            if ($amount > 0.0) {
                $reward = new RewardComputation(
                    originMemberId: $origin->id,
                    recipientMemberId: $upline->id,
                    level: $level,
                    metric: $this->plan->metric,
                    baseAmount: $baseAmount,
                    tier: $upline->tier,
                    tierMultiplier: $multiplier,
                    levelFactor: $factor,
                    amount: $amount,
                    context: $context,
                );
                $this->sink->pay($reward);
                $rewards[] = $reward;
            }

            $currentId = $upline->sponsorId;
        }

        return $rewards;
    }
}
