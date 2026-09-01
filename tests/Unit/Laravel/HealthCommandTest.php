<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit\Laravel;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PulseIndex\Client;
use PulseIndex\ClientInterface;
use PulseIndex\Exception\GrpcException;
use PulseIndex\Laravel\PulseIndexServiceProvider;
use PulseIndex\RecoveryState;
use PulseIndex\Tests\Concerns\CreatesOutboxTable;
use PulseIndex\Tests\Fixtures\Property;

final class HealthCommandTest extends TestCase
{
    use CreatesOutboxTable;

    protected function getPackageProviders($app): array
    {
        return [PulseIndexServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('pulseindex.outbox.dispatch', false);
        $app['config']->set('pulseindex.outbox.connections', ['testing']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->createOutboxTable();
    }

    public function test_engine_is_reachable_even_when_the_key_may_not_read_recovery_state(): void
    {
        // The regression this was rewritten for. getRecoveryState() is
        // operator-only and customer keys may not call it, so the old command
        // reported a perfectly healthy service as
        // UNREACHABLE for every customer — the catch could not tell "denied"
        // from "down".
        $client = $this->createMock(ClientInterface::class);
        $client->method('servingStatus')->willReturn(1);
        $client->method('getRecoveryState')
            ->willThrowException(new GrpcException('insufficient scope', 7));
        $this->app->instance(ClientInterface::class, $client);
        $this->app->instance(Client::class, $client);

        $this->artisan('pulse:health --json')
            ->assertExitCode(0)
            ->expectsOutputToContain('engine_reachable');
    }

    private function engine(bool $reachable = true, bool $needsFullReindex = false, int $indexed = 5): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        // Reachability and degradation now come from the health service, which
        // needs no scope. GetRecoveryState stays mocked because the command
        // still asks it for the index size — but only as a nicety, and never in
        // a way that can mark a reachable engine as down.
        if ($reachable) {
            $client->method('servingStatus')->willReturn($needsFullReindex ? 2 : 1);
            $client->method('getRecoveryState')->willReturn(new RecoveryState(
                lastCdcOffset: 0, indexedCount: $indexed, chunkCount: 0,
                mutationsSinceSnapshot: 0, needsFullReindex: $needsFullReindex,
            ));
        } else {
            $client->method('servingStatus')->willThrowException(new GrpcException('connection refused', 14));
            $client->method('getRecoveryState')->willThrowException(new GrpcException('connection refused', 14));
        }
        $this->app->instance(ClientInterface::class, $client);
        $this->app->instance(Client::class, $client);

        return $client;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function marker(array $overrides = []): array
    {
        $now = Carbon::now();

        return array_merge([
            'model_type' => Property::class, 'model_key' => (string) random_int(1, 1_000_000),
            'entity_id' => 1, 'tenant_id' => 'acme', 'operation' => 'upsert',
            'revision' => 0, 'attempts' => 0, 'available_at' => $now,
            'created_at' => $now, 'updated_at' => $now, 'failed_at' => null, 'last_error' => null,
        ], $overrides);
    }

    public function test_healthy_when_everything_is_green(): void
    {
        $this->engine();

        $this->artisan('pulse:health')->assertExitCode(0);
    }

    public function test_json_shape(): void
    {
        $this->engine(indexed: 7);
        $exit = $this->artisan('pulse:health', ['--json' => true])->run();
        self::assertSame(0, $exit);
    }

    public function test_json_output_is_valid_json_with_the_expected_keys(): void
    {
        $this->engine(indexed: 7);
        \Illuminate\Support\Facades\Artisan::call('pulse:health', ['--json' => true]);
        $payload = json_decode(\Illuminate\Support\Facades\Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['healthy']);
        self::assertSame(7, $payload['engine']['indexed_count']);
        self::assertFalse($payload['engine']['needs_full_reindex']);
        self::assertArrayHasKey('oldest_pending_seconds', $payload['outbox']);
        self::assertIsArray($payload['checks']);
    }

    public function test_unhealthy_when_engine_unreachable(): void
    {
        $this->engine(reachable: false);

        $this->artisan('pulse:health')
            ->expectsOutputToContain('UNHEALTHY')
            ->assertExitCode(1);
    }

    public function test_unhealthy_on_needs_full_reindex(): void
    {
        $this->engine(needsFullReindex: true);

        $this->artisan('pulse:health')->assertExitCode(1);
    }

    public function test_unhealthy_and_surfaces_a_failed_marker(): void
    {
        $this->engine();
        DB::table('pulseindex_outbox')->insert($this->marker([
            'failed_at' => Carbon::now(), 'last_error' => 'engine RESOURCE_EXHAUSTED',
        ]));

        $this->artisan('pulse:health')
            ->expectsOutputToContain('engine RESOURCE_EXHAUSTED')
            ->assertExitCode(1);
    }

    public function test_unhealthy_on_backlog_over_threshold(): void
    {
        config()->set('pulseindex.health.max_pending', 1);
        $this->engine();
        DB::table('pulseindex_outbox')->insert([$this->marker(), $this->marker()]);

        $this->artisan('pulse:health')->assertExitCode(1);
    }

    public function test_unhealthy_on_lag_over_threshold(): void
    {
        config()->set('pulseindex.health.max_lag_seconds', 60);
        $this->engine();
        DB::table('pulseindex_outbox')->insert($this->marker([
            'created_at' => Carbon::now()->subMinutes(10),
        ]));

        $this->artisan('pulse:health')
            ->expectsOutputToContain('outbox_lag')
            ->assertExitCode(1);
    }
}
