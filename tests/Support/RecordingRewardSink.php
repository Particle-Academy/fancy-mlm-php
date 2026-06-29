<?php

declare(strict_types=1);

namespace FancyMlm\Tests\Support;

use FancyMlm\Contracts\RewardSink;
use FancyMlm\Referral\RewardComputation;

/** Captures every paid reward so tests can assert against them. */
final class RecordingRewardSink implements RewardSink
{
    /** @var list<RewardComputation> */
    public array $paid = [];

    public function pay(RewardComputation $reward): void
    {
        $this->paid[] = $reward;
    }
}
