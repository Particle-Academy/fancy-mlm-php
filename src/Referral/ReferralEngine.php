<?php

declare(strict_types=1);

namespace FancyMlm\Referral;

use FancyMlm\Contracts\MemberRepository;
use FancyMlm\Contracts\RewardSink;
use FancyMlm\Plan\CompensationPlan;
use FancyMlm\Tree\TreeFactory;

/**
 * Distribute a referral reward from the member who acted, up the tree the plan
 * configures — unilevel (sponsor tree), binary or matrix (placement tree). The
 * plan's {@see TreeStrategy} does the walk; each level pays
 *
 *     amount = baseAmount × levelFactor(level) × tierMultiplier(uplineTier)
 *
 * with dynamic compression + a cycle guard. Every computed reward is handed to
 * the {@see RewardSink}; the full list is also returned.
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

        $rewards = TreeFactory::for($this->plan)
            ->distribute($origin, $baseAmount, $this->plan, $this->members, $context);

        foreach ($rewards as $reward) {
            $this->sink->pay($reward);
        }

        return $rewards;
    }
}
