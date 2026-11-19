<?php

declare(strict_types=1);

namespace FancyMlm\Tests\Laravel;

use FancyMlm\Laravel\MlmServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;

/**
 * A hand-built Laravel application for the bridge suite (laravel/framework
 * only, no orchestra/testbench), in a throwaway base path: no `.env` content,
 * no package discovery, the framework's own default config plus the overrides
 * below. In-memory SQLite and blank provider keys are forced.
 */
abstract class TestCase extends LaravelTestCase
{
    /**
     * `artisan()` returns the exit code. Laravel's output expectations are
     * built on Mockery, which is not a dependency here; read
     * `Artisan::output()` instead.
     */
    public $mockConsoleOutput = false;

    public function createApplication(): Application
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-mlm-php-tests';
        foreach (['bootstrap/cache', 'config', 'database', 'storage/framework/cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $dir) {
            if (! is_dir($base.'/'.$dir)) {
                mkdir($base.'/'.$dir, 0777, true);
            }
        }
        if (! is_file($base.'/.env')) {
            touch($base.'/.env');
        }

        $app = Application::configure(basePath: $base)->withMiddleware()->withExceptions()->create();

        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $config = $app['config'];
            $config->set('app.env', 'testing');
            $app->detectEnvironment(fn (): string => 'testing');
            $config->set('app.providers', array_merge($config->get('app.providers', []), $this->getPackageProviders($app)));
            $config->set('cache.default', 'array');
            $config->set('session.driver', 'array');
            $config->set('queue.default', 'sync');
            $config->set('mail.default', 'array');
            $config->set('logging.default', 'null');
            $config->set('services.anthropic.key', '');
            $config->set('services.openai.key', '');
        });

        // After every provider has registered and before any boots.
        $app->afterBootstrapping(RegisterProviders::class, function (Application $app): void {
            $this->defineEnvironment($app);
        });

        $app->make(Kernel::class)->bootstrap();

        $app['router']->getRoutes()->refreshNameLookups();
        $app['router']->getRoutes()->refreshActionLookups();

        return $app;
    }

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
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));

        // No fun-lab in the test harness — exercise the wiring with a Null/recording sink.
        $app['config']->set('mlm.fun_lab.enabled', false);
    }
}
