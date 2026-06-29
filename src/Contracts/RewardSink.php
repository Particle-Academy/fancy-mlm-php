<?php

declare(strict_types=1);

namespace FancyMlm\Contracts;

use FancyMlm\Referral\RewardComputation;

/**
 * Port that receives each computed reward. Implementations decide what "paying"
 * means: award fun-lab points/XP (engagement), write a commission ledger row
 * (monetary), enqueue a job, etc. The engine never knows which.
 */
interface RewardSink
{
    public function pay(RewardComputation $reward): void;
}
