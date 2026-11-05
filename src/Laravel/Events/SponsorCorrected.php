<?php

declare(strict_types=1);

namespace FancyMlm\Laravel\Events;

use FancyMlm\Attribution\SponsorCorrection;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A sponsor was corrected through `Mlm::correctSponsor()`. Dispatched after the
 * transaction commits, so a listener never sees a correction that rolled back.
 * Listen to notify the members involved, recompute commissions, or ship the
 * audit row to your own log.
 */
final class SponsorCorrected implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly SponsorCorrection $correction) {}
}
