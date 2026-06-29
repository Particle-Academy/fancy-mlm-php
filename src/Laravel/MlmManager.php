<?php

declare(strict_types=1);

namespace FancyMlm\Laravel;

use FancyMlm\Plan\CompensationPlan;
use FancyMlm\Referral\ReferralEngine;
use FancyMlm\Referral\RewardComputation;

/**
 * The `Mlm` facade target — a thin host-facing API over the engine.
 */
class MlmManager
{
    public function __construct(
        private readonly ReferralEngine $engine,
        private readonly CompensationPlan $plan,
        private readonly EloquentMemberRepository $members,
    ) {}

    public function plan(): CompensationPlan
    {
        return $this->plan;
    }

    /**
     * Distribute a referral reward up the sponsor tree from a member.
     *
     * @param array<string,mixed> $context
     * @return list<RewardComputation>
     */
    public function distribute(string $originMemberId, float $base, array $context = []): array
    {
        return $this->engine->distribute($originMemberId, $base, $context);
    }

    /**
     * Distribute from the member belonging to a host user id.
     *
     * @param array<string,mixed> $context
     * @return list<RewardComputation>
     */
    public function distributeForUser(string $userId, float $base, array $context = []): array
    {
        $origin = $this->members->findByUserId($userId);

        return $origin ? $this->engine->distribute($origin->id, $base, $context) : [];
    }
}
