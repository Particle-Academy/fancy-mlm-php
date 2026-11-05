<?php

declare(strict_types=1);

namespace FancyMlm\Contracts;

use FancyMlm\Attribution\SponsorCorrection;

/**
 * Optional companion to {@see AttributionStore} for stores that can record an
 * audited sponsor correction. Separate so that a host's own AttributionStore
 * keeps compiling: without it, `correctSponsor()` refuses with a LogicException
 * and every other method works as before.
 *
 * There is deliberately no update or delete here. Audit rows are append-only.
 */
interface SponsorCorrectionStore
{
    /**
     * Atomically append the audit row and move the member's sponsor from
     * `fromSponsorId` to `toSponsorId`. If the sponsor is no longer
     * `fromSponsorId`, throw {@see \FancyMlm\Attribution\Exceptions\SponsorImmutable}
     * and write nothing, so two racing corrections cannot both apply.
     *
     * The audit row is written FIRST: where the database guard is installed,
     * it is what lets the sponsor change through.
     */
    public function applySponsorCorrection(SponsorCorrection $correction): SponsorCorrection;

    /**
     * Every correction of this member, oldest first.
     *
     * @return list<SponsorCorrection>
     */
    public function sponsorCorrections(string $memberId): array;
}
