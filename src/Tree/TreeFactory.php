<?php

declare(strict_types=1);

namespace FancyMlm\Tree;

use FancyMlm\Plan\CompensationPlan;

/** Resolves a {@see CompensationPlan}'s tree type to its {@see TreeStrategy}. */
final class TreeFactory
{
    public static function for(CompensationPlan $plan): TreeStrategy
    {
        return match ($plan->tree) {
            CompensationPlan::TREE_BINARY => new BinaryTree(),
            CompensationPlan::TREE_MATRIX => new MatrixTree(),
            default => new UnilevelTree(),
        };
    }
}
