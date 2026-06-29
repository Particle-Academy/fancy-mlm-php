<?php

declare(strict_types=1);

namespace FancyMlm\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \FancyMlm\Plan\CompensationPlan plan()
 * @method static list<\FancyMlm\Referral\RewardComputation> distribute(string $originMemberId, float $base, array $context = [])
 * @method static list<\FancyMlm\Referral\RewardComputation> distributeForUser(string $userId, float $base, array $context = [])
 *
 * @see \FancyMlm\Laravel\MlmManager
 */
class Mlm extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'mlm';
    }
}
