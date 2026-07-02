<?php

declare(strict_types=1);

namespace FancyMlm\Tree;

use FancyMlm\Member;
use FancyMlm\Plan\CompensationPlan;

/** Unlimited frontline; the reward climbs the SPONSOR (enroller) tree. */
final class UnilevelTree extends UpwardTree
{
    public function key(): string
    {
        return CompensationPlan::TREE_UNILEVEL;
    }

    protected function parentId(Member $member): ?string
    {
        return $member->sponsorId;
    }
}
