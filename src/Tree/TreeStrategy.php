<?php

declare(strict_types=1);

namespace FancyMlm\Tree;

use FancyMlm\Contracts\MemberRepository;
use FancyMlm\Member;
use FancyMlm\Plan\CompensationPlan;
use FancyMlm\Referral\RewardComputation;

/**
 * A downline shape. Each strategy decides which parent pointer the engine climbs
 * to distribute a reward (sponsor tree vs placement tree) and how wide a node's
 * frontline may be — so one engine serves unilevel, binary, and matrix plans.
 */
interface TreeStrategy
{
    /** unilevel | binary | matrix */
    public function key(): string;

    /** Max direct frontline under a node (0 = unlimited). Binary = 2, matrix = plan width. */
    public function frontlineCap(CompensationPlan $plan): int;

    /**
     * Distribute a reward up this tree from the member who acted.
     *
     * @param array<string,mixed> $context
     * @return list<RewardComputation>
     */
    public function distribute(
        Member $origin,
        float $baseAmount,
        CompensationPlan $plan,
        MemberRepository $members,
        array $context,
    ): array;
}
