<?php

declare(strict_types=1);

namespace PulseIndex\Laravel;

use Illuminate\Support\ServiceProvider;
use PulseIndex\Client;
use PulseIndex\ClientInterface;

final class PulseIndexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/pulseindex.php', 'pulseindex');

        $this->app->singleton(Client::class, function ($app): Client {
            $config = $app['config']->get('pulseindex', []);

            $timeoutUs = $config['timeout_us'] ?? null;
            if ($timeoutUs === null || $timeoutUs === '') {
                $timeoutUs = (int) round(((float) ($config['timeout'] ?? 5)) * 1_000_000);
            }

            return new Client([
                'host' => (string) ($config['host'] ?? 'localhost:50051'),
                'api_key' => $config['api_key'] ?? null,
                'ssl' => filter_var($config['ssl'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'timeout_us' => (int) $timeoutUs,
            ]);
        });

        $this->app->bind(ClientInterface::class, fn ($app) => $app->make(Client::class));
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
