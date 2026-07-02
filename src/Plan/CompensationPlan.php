<?php

declare(strict_types=1);

namespace FancyMlm\Plan;

/**
 * The configurable rules of a referral program. This is the cross-language
 * artifact: the same JSON ({@see fromArray}/{@see toArray}) loads into the PHP
 * engine and the Node mirror, so both produce identical rewards.
 *
 * The {@see $tree} type decides how a reward flows and which parent pointer the
 * engine climbs:
 *  - `unilevel` — up the SPONSOR tree, unlimited frontline width.
 *  - `binary`   — up the PLACEMENT tree, two legs per node.
 *  - `matrix`   — up the PLACEMENT tree, a fixed {@see $width}×depth grid.
 *
 * In every tree the reward at each level is `base × levelFactor(level) ×
 * tierMultiplier(uplineTier)`, decaying per level ({@see $levelFactors}) and
 * scaled by tier ({@see $tiers}). With {@see $compression}, inactive uplines are
 * skipped so the next active member earns that level instead of it being lost.
 */
final class CompensationPlan
{
    public const TREE_UNILEVEL = 'unilevel';
    public const TREE_BINARY = 'binary';
    public const TREE_MATRIX = 'matrix';

    /**
     * @param list<float>        $levelFactors index 0 = level 1 (the direct sponsor)
     * @param array<string,Tier> $tiers        keyed by tier key
     * @param string             $tree         unilevel | binary | matrix
     * @param int                $width        frontline cap for matrix (0 = unlimited); 2 is implied for binary
     */
    public function __construct(
        public readonly string $metric,
        public readonly array $levelFactors,
        public readonly array $tiers = [],
        public readonly bool $compression = true,
        public readonly string $defaultTier = 'default',
        public readonly string $tree = self::TREE_UNILEVEL,
        public readonly int $width = 0,
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
            tree: (string) ($data['tree'] ?? self::TREE_UNILEVEL),
            width: (int) ($data['width'] ?? 0),
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
            'tree' => $this->tree,
            'width' => $this->width,
        ];
    }
}
