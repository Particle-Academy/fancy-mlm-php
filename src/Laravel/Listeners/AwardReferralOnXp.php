<?php

declare(strict_types=1);

namespace FancyMlm\Laravel\Listeners;

use FancyMlm\Laravel\EloquentMemberRepository;
use FancyMlm\Referral\ReferralEngine;

/**
 * The referral loop: when a member earns fun-lab XP for an action, credit their
 * upline. Listens to {@see \LaravelFunLab\Events\XpAwarded} (wired only when
 * fun-lab is installed, so the event is type-hinted loosely as `object`).
 *
 * Recursion guard: a referral bonus is itself awarded through fun-lab, which
 * re-fires XpAwarded — so awards carrying our own `source` are ignored.
 */
class AwardReferralOnXp
{
    public function __construct(
        private readonly ReferralEngine $engine,
        private readonly EloquentMemberRepository $members,
    ) {}

    public function handle(object $event): void
    {
        $source = $event->source ?? null;
        if ($source !== null && $source === config('mlm.reward_source', 'mlm')) {
            return; // our own re-award — do not cascade
        }

        $triggers = (array) config('mlm.fun_lab.trigger_metrics', []);
        if ($triggers !== []) {
            $metric = $event->gamedMetric->slug ?? null;
            if (! in_array($metric, $triggers, true)) {
                return;
            }
        }

        $recipient = $event->recipient ?? null;
        if ($recipient === null || ! method_exists($recipient, 'getKey')) {
            return;
        }

        $origin = $this->members->findByUserId((string) $recipient->getKey());
        if ($origin === null) {
            return;
        }

        $base = (float) ($event->amount ?? 0) * (float) config('mlm.fun_lab.base_factor', 1.0);
        if ($base <= 0.0) {
            return;
        }

        $this->engine->distribute($origin->id, $base, [
            'trigger' => 'fun_lab.xp_awarded',
            'metric' => $event->gamedMetric->slug ?? null,
        ]);
    }
}
