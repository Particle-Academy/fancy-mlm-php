<?php

declare(strict_types=1);

namespace FancyMlm\Tests\Laravel;

use FancyMlm\Laravel\MlmServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [MlmServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('mlm.plan', [
            'metric' => 'referral-bonus',
            'levelFactors' => [1.0, 0.5, 0.25],
            'tiers' => ['default' => 1.0, 'silver' => 1.25, 'gold' => 1.5],
            'compression' => true,
            'defaultTier' => 'default',
        ]);
        // No fun-lab in the test harness — exercise the wiring with a Null/recording sink.
        $app['config']->set('mlm.fun_lab.enabled', false);
    }
}
