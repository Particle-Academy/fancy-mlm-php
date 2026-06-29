<?php

declare(strict_types=1);

namespace FancyMlm\Tests\Support;

use FancyMlm\Contracts\MemberRepository;
use FancyMlm\Member;

/** In-memory {@see MemberRepository} for tests / plain-PHP hosts. */
final class ArrayMemberRepository implements MemberRepository
{
    /** @var array<string,Member> */
    private array $byId = [];

    public function __construct(Member ...$members)
    {
        foreach ($members as $member) {
            $this->byId[$member->id] = $member;
        }
    }

    public function find(string $id): ?Member
    {
        return $this->byId[$id] ?? null;
    }
}
