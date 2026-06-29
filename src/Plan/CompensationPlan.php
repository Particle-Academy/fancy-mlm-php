<?php

declare(strict_types=1);

namespace FancyMlm\Plan;

/**
 * The configurable rules of a referral program. This is the cross-language
 * artifact: the same JSON ({@see fromArray}/{@see toArray}) loads into the PHP
 * engine and the Node mirror, so both produce identical rewards.
 *
 * MVP shape (Unilevel referral): a reward flows up the sponsor tree, decaying
 * per level ({@see $levelFactors}) and scaled by each upline member's tier
 * ({@see $tiers}). With {@see $compression}, inactive uplines are skipped so the
 * next active member earns that level instead of it being lost.
 */
final class CompensationPlan
{
    /**
     * @param list<float>        $levelFactors index 0 = level 1 (the direct sponsor)
     * @param array<string,Tier> $tiers        keyed by tier key
     */
    public function __construct(
        public readonly string $metric,
        public readonly array $levelFactors,
        public readonly array $tiers = [],
        public readonly bool $compression = true,
        public readonly string $defaultTier = 'default',
    ) {}

    /** Number of upline levels this plan rewards. */
    public function levels(): int
    {
        return count($this->levelFactors);
    }

    /** Reward factor for a 1-based level (0.0 beyond the configured depth). */
    public function levelFactor(int $level): float
    {
        return $this->levelFactors[$level - 1] ?? 0.0;
    }

    /** Multiplier for a tier key, falling back to the default tier, then 1.0. */
    public function tierMultiplier(string $tierKey): float
    {
        return $this->tiers[$tierKey]->multiplier
            ?? $this->tiers[$this->defaultTier]->multiplier
            ?? 1.0;
    }

    /**
     * Build from a plain array (the shared plan JSON). `tiers` accepts either a
     * scalar multiplier (`{"gold": 1.5}`) or an object
     * (`{"gold": {"multiplier": 1.5, "label": "Gold"}}`).
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $tiers = [];
        foreach ((array) ($data['tiers'] ?? []) as $key => $value) {
            $key = (string) $key;
            $tiers[$key] = is_array($value)
                ? new Tier($key, (float) ($value['multiplier'] ?? 1.0), isset($value['label']) ? (string) $value['label'] : null)
                : new Tier($key, (float) $value);
        }

        $factors = array_map(static fn ($f): float => (float) $f, array_values((array) ($data['levelFactors'] ?? [1.0])));

        return new self(
            metric: (string) ($data['metric'] ?? 'referral-bonus'),
            levelFactors: $factors,
            tiers: $tiers,
            compression: (bool) ($data['compression'] ?? true),
            defaultTier: (string) ($data['defaultTier'] ?? 'default'),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        $tiers = [];
        foreach ($this->tiers as $key => $tier) {
            $tiers[$key] = ['multiplier' => $tier->multiplier, 'label' => $tier->label];
        }

        return [
            'metric' => $this->metric,
            'levelFactors' => $this->levelFactors,
            'tiers' => $tiers,
            'compression' => $this->compression,
            'defaultTier' => $this->defaultTier,
        ];
    }
}
