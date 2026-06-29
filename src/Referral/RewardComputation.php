<?php

declare(strict_types=1);

namespace FancyMlm\Referral;

/**
 * One reward the engine computed for one upline member, fully traced back to the
 * referral action that triggered it (the origin), the level reached, and the
 * exact factors applied — so a sink can record an auditable, reversible entry.
 */
final class RewardComputation
{
    /**
     * @param array<string,mixed> $context arbitrary host context (e.g. the source action)
     */
    public function __construct(
        public readonly string $originMemberId,
        public readonly string $recipientMemberId,
        public readonly int $level,
        public readonly string $metric,
        public readonly float $baseAmount,
        public readonly string $tier,
        public readonly float $tierMultiplier,
        public readonly float $levelFactor,
        public readonly float $amount,
        public readonly array $context = [],
    ) {}

    /** Round the (float) reward to an integer — convenient for XP / points / cents. */
    public function amountAsInt(): int
    {
        return (int) round($this->amount);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'originMemberId' => $this->originMemberId,
            'recipientMemberId' => $this->recipientMemberId,
            'level' => $this->level,
            'metric' => $this->metric,
            'baseAmount' => $this->baseAmount,
            'tier' => $this->tier,
            'tierMultiplier' => $this->tierMultiplier,
            'levelFactor' => $this->levelFactor,
            'amount' => $this->amount,
            'context' => $this->context,
        ];
    }
}
