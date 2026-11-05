<?php

declare(strict_types=1);

namespace FancyMlm\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \FancyMlm\Plan\CompensationPlan plan()
 * @method static list<\FancyMlm\Referral\RewardComputation> distribute(string $originMemberId, float $base, array $context = [])
 * @method static list<\FancyMlm\Referral\RewardComputation> distributeForUser(string $userId, float $base, array $context = [])
 * @method static \FancyMlm\Attribution\ReferralProgram referrals()
 * @method static \FancyMlm\Attribution\ReferralCode issueCode(string $memberId, string|list<string> $scope, ?\DateTimeImmutable $expiresAt = null)
 * @method static bool revokeCode(string $code)
 * @method static \FancyMlm\Attribution\ScanResult recordScan(string $code, string $sessionId, array $context = [], ?\DateTimeImmutable $at = null)
 * @method static \FancyMlm\Attribution\ScanResult recordScanFromRequest(\Illuminate\Http\Request $request, string $code, array $context = [])
 * @method static \FancyMlm\Attribution\AttributionDecision attributeSignup(string $memberId, string $sessionId, ?\DateTimeImmutable $at = null)
 * @method static \FancyMlm\Attribution\AttributionDecision attributeSignupFromRequest(\Illuminate\Http\Request $request, string $memberId)
 * @method static void assignSponsor(string $memberId, string $sponsorId)
 * @method static list<\FancyMlm\Attribution\StatRow> stats(string $groupBy, \DateTimeImmutable $from, \DateTimeImmutable $to, array $filter = [])
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
