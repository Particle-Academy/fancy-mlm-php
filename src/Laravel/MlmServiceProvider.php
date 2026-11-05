<?php

declare(strict_types=1);

namespace FancyMlm\Laravel;

use FancyMlm\Attribution\AttributionPolicy;
use FancyMlm\Attribution\ReferralProgram;
use FancyMlm\Attribution\SessionHasher;
use FancyMlm\Contracts\AttributionStore;
use FancyMlm\Contracts\MemberRepository;
use FancyMlm\Laravel\Console\PruneScansCommand;
use RuntimeException;
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

        $this->registerAttribution();

        $this->app->singleton('mlm', static fn ($app): MlmManager => new MlmManager(
            $app->make(ReferralEngine::class),
            $app->make(CompensationPlan::class),
            $app->make(EloquentMemberRepository::class),
        ));
        $this->app->alias('mlm', MlmManager::class);
    }

    /**
     * Bound, not singletons: policy and keys are read from config on every
     * resolve, so a host changing them at runtime is not served a stale copy.
     */
    private function registerAttribution(): void
    {
        $this->app->bind(AttributionStore::class, EloquentAttributionStore::class);

        $this->app->bind(AttributionPolicy::class, static function ($app): AttributionPolicy {
            $config = (array) $app['config']->get('mlm.attribution', []);

            return new AttributionPolicy(
                (string) ($config['model'] ?? AttributionPolicy::FIRST_TOUCH),
                (int) ($config['window_days'] ?? 30) * 86400,
                (int) ($config['dedupe_minutes'] ?? 30) * 60,
            );
        });

        $this->app->bind(SessionHasher::class, static function ($app): SessionHasher {
            return new SessionHasher(self::sessionKey((string) $app['config']->get('mlm.attribution.session_key', ''), (string) $app['config']->get('app.key', '')));
        });

        $this->app->bind(ReferralProgram::class, static fn ($app): ReferralProgram => new ReferralProgram(
            $app->make(AttributionStore::class),
            $app->make(SessionHasher::class),
            $app->make(AttributionPolicy::class),
            null,
            (int) $app['config']->get('mlm.attribution.code_length', 16),
        ));
    }

    /**
     * The explicit key if set; otherwise a sub-key derived from APP_KEY, so the
     * app key itself is never used directly as an HMAC key for another purpose.
     */
    public static function sessionKey(string $explicit, string $appKey): string
    {
        if ($explicit !== '') {
            return $explicit;
        }
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }
        if ($appKey === '') {
            throw new RuntimeException('fancy-mlm: set MLM_SESSION_HASH_KEY or APP_KEY; session hashes are never unkeyed.');
        }

        return hash_hmac('sha256', 'fancy-mlm/session-hash/v1', $appKey);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PruneScansCommand::class]);
            $this->publishes([__DIR__.'/../../config/mlm.php' => $this->app->configPath('mlm.php')], 'mlm-config');
            $this->publishes([__DIR__.'/../../database/migrations' => $this->app->databasePath('migrations')], 'mlm-migrations');
        }

        // Wire the gamified referral loop only when fun-lab is installed + enabled.
        if ($this->app['config']->get('mlm.fun_lab.enabled', true) && class_exists(\LaravelFunLab\Events\XpAwarded::class)) {
            $this->app['events']->listen(\LaravelFunLab\Events\XpAwarded::class, AwardReferralOnXp::class);
        }
    }
}
