<?php

declare(strict_types=1);

namespace FancyMlm\Contracts;

use FancyMlm\Member;

/**
 * Port the host implements so the engine can read the network without knowing
 * how it's stored. The Laravel bridge ships an Eloquent implementation; a plain
 * PHP host can back it with an array.
 */
interface MemberRepository
{
    public function find(string $id): ?Member;
}
