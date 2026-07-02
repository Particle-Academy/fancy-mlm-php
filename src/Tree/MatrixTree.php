<?php

declare(strict_types=1);

namespace FancyMlm\Tree;

use FancyMlm\Member;
use FancyMlm\Plan\CompensationPlan;

/**
 * A forced W×depth matrix; the reward climbs the PLACEMENT tree, falling back to
 * the sponsor pointer when no separate placement is set. Frontline is capped at
 * the plan's {@see CompensationPlan::$width} (default 3 when unset).
 */
final class MatrixTree extends UpwardTree
{
    public function key(): string
    {
        return CompensationPlan::TREE_MATRIX;
    }

    public function frontlineCap(CompensationPlan $plan): int
    {
        return $plan->width > 0 ? $plan->width : 3;
    }

    protected function parentId(Member $member): ?string
    {
        return $member->placementId ?? $member->sponsorId;
    }
}
