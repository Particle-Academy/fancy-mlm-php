<?php

declare(strict_types=1);

namespace FancyMlm\Laravel;

use DateTimeImmutable;
use FancyMlm\Attribution\AttributionDecision;
use FancyMlm\Attribution\ReferralCode;
use FancyMlm\Attribution\ReferralProgram;
use FancyMlm\Attribution\ScanResult;
use FancyMlm\Attribution\StatRow;
use FancyMlm\Plan\CompensationPlan;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
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

    /**
     * The referral program, resolved fresh so config changes apply.
     */
    public function referrals(): ReferralProgram
    {
        return Container::getInstance()->make(ReferralProgram::class);
    }

    /** @param string|list<string> $scope host-defined: an opaque string or a tuple */
    public function issueCode(string $memberId, string|array $scope, ?DateTimeImmutable $expiresAt = null): ReferralCode
    {
        return $this->referrals()->issueCode($memberId, $scope, $expiresAt);
    }

    public function revokeCode(string $code): bool
    {
        return $this->referrals()->revokeCode($code);
    }

    /** @param array<string,mixed> $context stored only because you passed it */
    public function recordScan(string $code, string $sessionId, array $context = [], ?DateTimeImmutable $at = null): ScanResult
    {
        return $this->referrals()->recordScan($code, $sessionId, $context, $at);
    }

    /**
     * Record a scan for the visitor on this request. Reads the session and
     * nothing else: never `$request->ip()`, never the user agent.
     *
     * @param array<string,mixed> $context stored only because you passed it
     */
    public function recordScanFromRequest(Request $request, string $code, array $context = []): ScanResult
    {
        return $this->recordScan($code, RequestSession::id($request), $context);
    }

    public function attributeSignup(string $memberId, string $sessionId, ?DateTimeImmutable $at = null): AttributionDecision
    {
        return $this->referrals()->attributeSignup($memberId, $sessionId, $at);
    }

    public function attributeSignupFromRequest(Request $request, string $memberId): AttributionDecision
    {
        return $this->attributeSignup($memberId, RequestSession::id($request));
    }

    public function assignSponsor(string $memberId, string $sponsorId): void
    {
        $this->referrals()->assignSponsor($memberId, $sponsorId);
    }

    /**
     * @param 'code'|'scope'|'member' $groupBy
     * @param array<string,string> $filter
     * @return list<StatRow>
     */
    public function stats(string $groupBy, DateTimeImmutable $from, DateTimeImmutable $to, array $filter = []): array
    {
        return $this->referrals()->stats($groupBy, $from, $to, $filter);
    }
}
