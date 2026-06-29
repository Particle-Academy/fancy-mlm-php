<?php

declare(strict_types=1);

namespace FancyMlm\Laravel;

use FancyMlm\Contracts\MemberRepository;
use FancyMlm\Contracts\RewardSink;
use FancyMlm\Laravel\Listeners\AwardReferralOnXp;
use FancyMlm\Plan\CompensationPlan;
use FancyMlm\Referral\ReferralEngine;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the framework-agnostic engine into Laravel. Apps not using Laravel can
 * ignore this file entirely and construct {@see ReferralEngine} directly.
 */
class MlmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/mlm.php', 'mlm');

        $this->app->singleton(
            CompensationPlan::class,
            static fn ($app): CompensationPlan => CompensationPlan::fromArray((array) $app['config']->get('mlm.plan', [])),
        );

        $this->app->bind(MemberRepository::class, EloquentMemberRepository::class);

        $this->app->bind(RewardSink::class, function ($app): RewardSink {
            $source = (string) $app['config']->get('mlm.reward_source', 'mlm');

            if ($app['config']->get('mlm.fun_lab.enabled', true) && class_exists(\LaravelFunLab\Facades\LFL::class)) {
                return new FunLabRewardSink($source);
            }

            return new NullRewardSink();
        });

        $this->app->singleton(ReferralEngine::class, static fn ($app): ReferralEngine => new ReferralEngine(
            $app->make(CompensationPlan::class),
            $app->make(MemberRepository::class),
            $app->make(RewardSink::class),
        ));

        $this->app->singleton('mlm', static fn ($app): MlmManager => new MlmManager(
            $app->make(ReferralEngine::class),
            $app->make(CompensationPlan::class),
            $app->make(EloquentMemberRepository::class),
        ));
        $this->app->alias('mlm', MlmManager::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../../config/mlm.php' => $this->app->configPath('mlm.php')], 'mlm-config');
            $this->publishes([__DIR__.'/../../database/migrations' => $this->app->databasePath('migrations')], 'mlm-migrations');
        }

        // Wire the gamified referral loop only when fun-lab is installed + enabled.
        if ($this->app['config']->get('mlm.fun_lab.enabled', true) && class_exists(\LaravelFunLab\Events\XpAwarded::class)) {
            $this->app['events']->listen(\LaravelFunLab\Events\XpAwarded::class, AwardReferralOnXp::class);
        }
    }
}
