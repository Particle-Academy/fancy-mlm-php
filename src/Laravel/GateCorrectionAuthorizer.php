<?php

declare(strict_types=1);

namespace FancyMlm\Laravel;

use FancyMlm\Attribution\SponsorCorrectionRequest;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * Authorizes `Mlm::correctSponsor()` through a Gate the HOST defines:
 *
 *     Gate::define(GateCorrectionAuthorizer::ABILITY,
 *         fn (User $user, SponsorCorrectionRequest $request) => $user->isSupportLead());
 *
 * Default deny: if the ability is not defined, every correction is refused,
 * even when a `Gate::before()` hook would otherwise allow any ability. A
 * policy-based definition does not count; use `Gate::define()`.
 *
 * The Gate is asked as the signed-in user. With nobody signed in (a queued job,
 * a console command) Laravel denies unless the callback's user parameter is
 * nullable (`?User $user`), in which case `$request->actorId` is how you tell
 * who is acting.
 */
final class GateCorrectionAuthorizer
{
    public const ABILITY = 'fancy-mlm.correct-sponsor';

    public function __construct(private readonly Gate $gate) {}

    public function __invoke(SponsorCorrectionRequest $request): bool
    {
        if (! $this->gate->has(self::ABILITY)) {
            return false;
        }

        return $this->gate->allows(self::ABILITY, [$request]);
    }
}
