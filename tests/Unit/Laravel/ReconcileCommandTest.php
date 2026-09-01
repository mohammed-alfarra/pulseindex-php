<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit\Laravel;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PulseIndex\Client;
use PulseIndex\ClientInterface;
use PulseIndex\Laravel\PulseIndexServiceProvider;
use PulseIndex\QueryBuilder;
use PulseIndex\RecoveryState;
use PulseIndex\SearchResult;
use PulseIndex\Tests\Concerns\CreatesOutboxTable;
use PulseIndex\Tests\Fixtures\Property;

final class ReconcileCommandTest extends TestCase
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
        $app['config']->set('pulseindex.tenant_id', 'acme');
        $app['config']->set('pulseindex.searchable_models', [Property::class]);
        $app['config']->set('pulseindex.outbox.dispatch', false);
        $app['config']->set('pulseindex.outbox.connections', ['testing']);
        $app['config']->set('pulseindex.reconcile.page_size', 1000);
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
        $this->createOutboxTable();
    }

    private function client(int $indexedCount, array $engineIds, bool $needsFullReindex = false): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        // The degraded gate reads the health service, not GetRecoveryState:
        // that RPC needs `admin`, which no tenant-bound key may hold.
        $client->method('servingStatus')->willReturn($needsFullReindex ? 2 : 1);
        $client->method('getRecoveryState')->willReturn(new RecoveryState(
            lastCdcOffset: 0, indexedCount: $indexedCount, chunkCount: 0,
            mutationsSinceSnapshot: 0, needsFullReindex: $needsFullReindex,
        ));
        $client->method('search')->willReturnCallback(function (QueryBuilder $q) use ($engineIds): SearchResult {
            $req = $q->toRequest();
            $slice = array_slice($engineIds, $req->getOffset(), $req->getLimit());

            return new SearchResult(matchedEntityIds: array_values($slice), totalMatches: count($engineIds), executionTimeUs: 1);
        });
        $this->app->instance(ClientInterface::class, $client);
        $this->app->instance(Client::class, $client);

        return $client;
    }

    private function seedProps(int $n): void
    {
        \PulseIndex\Laravel\PulseSync::withoutSyncing(function () use ($n): void {
            for ($i = 1; $i <= $n; $i++) {
                Property::query()->create(['status' => 'open', 'price' => $i]);
            }
        });
    }

    public function test_cheap_gate_skips_the_sweep_when_grand_totals_match(): void
    {
        $this->seedProps(3);
        $client = $this->client(indexedCount: 3, engineIds: [1, 2, 3]);
        $client->expects(self::never())->method('search');   // gate short-circuits

        $this->artisan('pulse:reconcile')->assertExitCode(0);
    }

    public function test_dry_run_reports_drift_and_exits_non_zero(): void
    {
        $this->seedProps(5);
        $this->client(indexedCount: 3, engineIds: [1, 2, 3]);

        $this->artisan('pulse:reconcile')
            ->expectsOutputToContain('--apply')
            ->assertFailed();

        self::assertSame(0, DB::table('pulseindex_outbox')->count());
    }

    public function test_apply_enqueues_markers_for_missing_and_orphans(): void
    {
        $this->seedProps(4);                                    // db: 1..4
        $this->client(indexedCount: 99, engineIds: [1, 2, 8]);  // missing 3,4 ; orphan 8

        $this->artisan('pulse:reconcile', ['--apply' => true, '--full' => true])->assertExitCode(0);

        self::assertEqualsCanonicalizing(
            ['3', '4'],
            DB::table('pulseindex_outbox')->where('operation', 'upsert')->pluck('model_key')->all(),
        );
        self::assertSame(
            ['8'],
            DB::table('pulseindex_outbox')->where('operation', 'delete')->pluck('model_key')->all(),
        );
    }

    public function test_orphan_brake_blocks_apply_without_force(): void
    {
        config()->set('pulseindex.reconcile.max_orphans', 1);
        config()->set('pulseindex.reconcile.max_orphan_ratio', 0.0);
        $this->seedProps(1);
        $this->client(indexedCount: 99, engineIds: [1, 50, 51, 52]);   // 3 orphans

        $this->artisan('pulse:reconcile', ['--apply' => true, '--full' => true])
            ->expectsOutputToContain('exceeds the brake')
            ->assertFailed();

        self::assertSame(0, DB::table('pulseindex_outbox')->where('operation', 'delete')->count());

        $this->artisan('pulse:reconcile', ['--apply' => true, '--full' => true, '--force' => true])->assertExitCode(0);
        self::assertSame(3, DB::table('pulseindex_outbox')->where('operation', 'delete')->count());
    }

    public function test_aborts_when_engine_is_in_degraded_recovery(): void
    {
        $this->seedProps(1);
        $this->client(indexedCount: 0, engineIds: [], needsFullReindex: true);

        $this->artisan('pulse:reconcile', ['--full' => true])
            ->expectsOutputToContain('degraded recovery')
            ->assertFailed();
    }

    public function test_aborts_when_outbox_is_backed_up(): void
    {
        config()->set('pulseindex.reconcile.pending_threshold', 2);
        $this->seedProps(1);
        $this->client(indexedCount: 0, engineIds: []);
        DB::table('pulseindex_outbox')->insert(array_map(fn ($i) => [
            'model_type' => Property::class, 'model_key' => (string) $i, 'entity_id' => $i,
            'tenant_id' => 'acme', 'operation' => 'upsert', 'revision' => 0, 'attempts' => 0,
            'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], [1, 2, 3]));

        $this->artisan('pulse:reconcile', ['--full' => true])
            ->expectsOutputToContain('pending markers')
            ->assertFailed();
    }
}
