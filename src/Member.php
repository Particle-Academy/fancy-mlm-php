<?php

declare(strict_types=1);

namespace FancyMlm;

/**
 * A participant in the network. Framework-agnostic value object — the host maps
 * its own user/member record onto this via a {@see \FancyMlm\Contracts\MemberRepository}.
 *
 * The engine carries two parent pointers so a single model serves every tree
 * type: {@see $sponsorId} (who personally referred you — the enroller tree, used
 * for referral/matching bonuses) and {@see $placementId} (where you sit in a
 * binary/matrix payout tree). Unilevel plans use only the sponsor tree.
 */
final class Member
{
    /**
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $sponsorId = null,
        public readonly string $tier = 'default',
        public readonly bool $active = true,
        public readonly ?string $placementId = null,
        public readonly array $meta = [],
    ) {}
}
