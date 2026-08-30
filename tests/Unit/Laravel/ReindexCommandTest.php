<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit\Laravel;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PulseIndex\AdminHttpClient;
use PulseIndex\Client;
use PulseIndex\ClientInterface;
use PulseIndex\Exception\PulseIndexException;
use PulseIndex\Laravel\PulseIndexServiceProvider;
use PulseIndex\Laravel\PulseSync;
use PulseIndex\RecoveryState;
use PulseIndex\Tests\Fixtures\Property;
use RuntimeException;

final class ReindexCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PulseIndexServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('pulseindex.tenant_id', 'acme');
        $app['config']->set('pulseindex.searchable_models', [Property::class]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('properties', function (Blueprint $table): void {
            $table->id();
            $table->string('status')->nullable();
            $table->unsignedInteger('price')->default(0);
            $table->json('tags')->nullable();
            $table->float('latitude')->nullable();
            $table->float('longitude')->nullable();
            $table->timestamps();
        });
    }

    private function fakeClient(): ClientInterface
    {
        // Real client would open a gRPC channel in setUp via the observer; keep the
        // observer inert by binding a no-op double up front.
        $client = $this->createMock(ClientInterface::class);
        $this->app->instance(ClientInterface::class, $client);
        $this->app->instance(Client::class, $client);

        return $client;
    }

    private function fakeAdmin(): AdminHttpClient
    {
        $admin = $this->createMock(AdminHttpClient::class);
        $this->app->instance(AdminHttpClient::class, $admin);

        return $admin;
    }

    private function makeRows(int $n): void
    {
        PulseSync::withoutSyncing(function () use ($n): void {
            for ($i = 1; $i <= $n; $i++) {
                Property::query()->create(['status' => 'open', 'price' => 100 + $i, 'tags' => ['feature:pool']]);
            }
        });
    }

    public function test_bootstrap_indexes_all_rows_without_touching_recovery(): void
    {
        $this->makeRows(3);
        $client = $this->fakeClient();
        $admin = $this->fakeAdmin();

        $seen = 0;
        $client->method('batchIndex')->willReturnCallback(function (array $entities) use (&$seen): int {
            $seen += count($entities);

            return count($entities);
        });
        $client->expects(self::never())->method('getRecoveryState');
        $admin->expects(self::never())->method('markReindexComplete');

        $this->artisan('pulse:reindex', ['model' => Property::class])->assertExitCode(0);

        self::assertSame(3, $seen);
    }

    public function test_recovery_reindexes_then_marks_complete(): void
    {
        $this->makeRows(2);
        $client = $this->fakeClient();
        $admin = $this->fakeAdmin();

        $client->method('getRecoveryState')->willReturn(
            new RecoveryState(lastCdcOffset: 0, indexedCount: 0, chunkCount: 0, mutationsSinceSnapshot: 0, needsFullReindex: true),
        );
        $client->expects(self::atLeastOnce())->method('batchIndex')->willReturn(2);
        $admin->expects(self::once())->method('markReindexComplete');

        $this->artisan('pulse:reindex', ['--recovery' => true])->assertExitCode(0);
    }

    public function test_recovery_refused_when_engine_is_healthy(): void
    {
        $this->makeRows(1);
        $client = $this->fakeClient();
        $admin = $this->fakeAdmin();

        $client->method('getRecoveryState')->willReturn(
            new RecoveryState(lastCdcOffset: 0, indexedCount: 5, chunkCount: 1, mutationsSinceSnapshot: 0, needsFullReindex: false),
        );
        $client->expects(self::never())->method('batchIndex');
        $admin->expects(self::never())->method('markReindexComplete');

        $this->artisan('pulse:reindex', ['--recovery' => true])->assertFailed();
    }

    public function test_recovery_rejects_a_model_argument(): void
    {
        $client = $this->fakeClient();
        $admin = $this->fakeAdmin();
        $client->expects(self::never())->method('getRecoveryState');
        $admin->expects(self::never())->method('markReindexComplete');

        $this->artisan('pulse:reindex', ['model' => Property::class, '--recovery' => true])->assertFailed();
    }

    public function test_batch_failure_aborts_without_marking_complete(): void
    {
        $this->makeRows(5);
        $client = $this->fakeClient();
        $admin = $this->fakeAdmin();

        $client->method('getRecoveryState')->willReturn(
            new RecoveryState(lastCdcOffset: 0, indexedCount: 0, chunkCount: 0, mutationsSinceSnapshot: 0, needsFullReindex: true),
        );
        $client->method('batchIndex')->willThrowException(new RuntimeException('engine RESOURCE_EXHAUSTED'));
        $admin->expects(self::never())->method('markReindexComplete');

        $this->artisan('pulse:reindex', ['--recovery' => true, '--chunk' => 2])
            ->expectsOutputToContain('chunk 1')
            ->assertFailed();
    }

    public function test_completion_call_failure_is_reported_as_failure(): void
    {
        $this->makeRows(2);
        $client = $this->fakeClient();
        $admin = $this->fakeAdmin();

        $client->method('getRecoveryState')->willReturn(
            new RecoveryState(lastCdcOffset: 0, indexedCount: 0, chunkCount: 0, mutationsSinceSnapshot: 0, needsFullReindex: true),
        );
        $client->method('batchIndex')->willReturn(2);
        $admin->method('markReindexComplete')->willThrowException(
            new PulseIndexException('engine reports the index is still empty; nothing was indexed'),
        );

        $this->artisan('pulse:reindex', ['--recovery' => true])
            ->expectsOutputToContain('did not clear needs_full_reindex')
            ->assertFailed();
    }

    public function test_observer_is_disabled_during_the_run(): void
    {
        $this->makeRows(1);
        $client = $this->fakeClient();
        $this->fakeAdmin();

        $enabledDuringRun = true;
        $client->method('batchIndex')->willReturnCallback(function (array $entities) use (&$enabledDuringRun): int {
            $enabledDuringRun = PulseSync::enabled();

            return count($entities);
        });

        $this->artisan('pulse:reindex', ['model' => Property::class])->assertExitCode(0);

        self::assertFalse($enabledDuringRun, 'observer sync must be suppressed while reindexing');
    }
}
