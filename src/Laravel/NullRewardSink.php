<?php

declare(strict_types=1);

namespace FancyMlm\Laravel;

use FancyMlm\Contracts\RewardSink;
use FancyMlm\Referral\RewardComputation;

/**
 * Default sink when no reward backend (fun-lab) is configured. It drops the
 * reward — but {@see \FancyMlm\Referral\ReferralEngine::distribute} still returns
 * the full list of computations, so a host can persist or pay them itself.
 */
class NullRewardSink implements RewardSink
{
    public function pay(RewardComputation $reward): void
    {
        // no-op
    }
}
