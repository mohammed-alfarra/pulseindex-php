<?php

declare(strict_types=1);

namespace PulseIndex\Laravel;

use Illuminate\Support\ServiceProvider;
use PulseIndex\Client;

final class PulseIndexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/pulseindex.php', 'pulseindex');

        $this->app->singleton(Client::class, function ($app): Client {
            $config = $app['config']->get('pulseindex', []);

            return new Client([
                'host' => (string) ($config['host'] ?? 'localhost:50051'),
                'api_key' => $config['api_key'] ?? null,
                'ssl' => (bool) ($config['ssl'] ?? false),
                'timeout_us' => (int) ($config['timeout_us'] ?? 5_000_000),
            ]);
        });

        $this->app->alias(Client::class, 'pulseindex');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/pulseindex.php' => $this->app->configPath('pulseindex.php'),
            ], 'pulseindex-config');
        }
    }
}
