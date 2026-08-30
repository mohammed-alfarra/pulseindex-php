<?php

declare(strict_types=1);

namespace PulseIndex\Laravel;

use Illuminate\Support\ServiceProvider;
use PulseIndex\AdminHttpClient;
use PulseIndex\Client;
use PulseIndex\ClientInterface;
use PulseIndex\Laravel\Commands\OutboxWorkCommand;
use PulseIndex\Laravel\Commands\ReindexCommand;

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

        $this->app->singleton(AdminHttpClient::class, function ($app): AdminHttpClient {
            $config = $app['config']->get('pulseindex', []);

            return AdminHttpClient::fromConfig(
                $config['admin_url'] ?? null,
                (string) ($config['host'] ?? 'localhost:50051'),
                (int) ($config['admin_port'] ?? 8081),
                $config['internal_token'] ?? null,
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/pulseindex.php' => $this->app->configPath('pulseindex.php'),
            ], 'pulseindex-config');

            $this->publishesMigrations([
                __DIR__ . '/../../database/migrations' => $this->app->databasePath('migrations'),
            ], 'pulseindex-migrations');

            $this->commands([ReindexCommand::class, OutboxWorkCommand::class]);
        }
    }
}
