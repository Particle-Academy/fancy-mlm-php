<?php

declare(strict_types=1);

namespace FancyMlm\Attribution;

/**
 * The outcome of matching a signup to a scan. `$reason` is one of:
 * attributed, already_attributed, already_sponsored, no_scan, code_inactive,
 * self_referral, cycle.
 */
final class AttributionDecision
{
    public const REASONS = ['attributed', 'already_attributed', 'already_sponsored', 'no_scan', 'code_inactive', 'self_referral', 'cycle'];

    public function __construct(
        public readonly bool $attributed,
        public readonly string $reason,
        public readonly ?string $sponsorMemberId = null,
        public readonly ?string $code = null,
        public readonly ?string $scope = null,
        public readonly ?string $scanId = null,
    ) {}

    public static function none(string $reason, ?string $sponsorMemberId = null): self
    {
        return new self(false, $reason, $sponsorMemberId);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'attributed' => $this->attributed,
            'reason' => $this->reason,
            'sponsorMemberId' => $this->sponsorMemberId,
            'code' => $this->code,
            'scope' => $this->scope,
            'scanId' => $this->scanId,
        ];
    }
}
