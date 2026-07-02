<?php

declare(strict_types=1);

namespace FancyMlm\Tree;

use FancyMlm\Member;
use FancyMlm\Plan\CompensationPlan;

/**
 * Two legs per node; the reward climbs the PLACEMENT tree (where a member sits
 * after spillover), falling back to the sponsor pointer when no separate
 * placement is set. Frontline is capped at 2.
 */
final class BinaryTree extends UpwardTree
{
    public function key(): string
    {
        return CompensationPlan::TREE_BINARY;
    }

    public function frontlineCap(CompensationPlan $plan): int
    {
        return 2;
    }

    protected function parentId(Member $member): ?string
    {
        return $member->placementId ?? $member->sponsorId;
    }
}
