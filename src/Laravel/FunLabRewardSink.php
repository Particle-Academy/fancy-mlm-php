<?php

declare(strict_types=1);

namespace FancyMlm\Laravel;

use FancyMlm\Contracts\RewardSink;
use FancyMlm\Laravel\Models\Member as MemberModel;
use FancyMlm\Referral\RewardComputation;
use LaravelFunLab\Facades\LFL;

/**
 * Pays each reward as fun-lab points/XP to the upline member's related user —
 * the gamified referral loop. Bound as the default {@see RewardSink} only when
 * laravel-fun-lab is installed (see {@see MlmServiceProvider}), so the
 * `LFL` reference here is never reached without the package present.
 */
class FunLabRewardSink implements RewardSink
{
    public function __construct(private readonly string $source = 'mlm') {}

    public function pay(RewardComputation $reward): void
    {
        $user = MemberModel::query()->find($reward->recipientMemberId)?->user;
        if ($user === null) {
            return;
        }

        LFL::award($reward->metric)
            ->to($user)
            ->amount($reward->amountAsInt())
            ->because('Referral bonus (level '.$reward->level.')')
            ->from($this->source)
            ->withMeta([
                'mlm' => true,
                'origin_member_id' => $reward->originMemberId,
                'level' => $reward->level,
                'tier' => $reward->tier,
                'tier_multiplier' => $reward->tierMultiplier,
            ])
            ->save();
    }
}
