<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PulseIndex\Client;
use PulseIndex\ClientInterface;
use PulseIndex\Laravel\OutboxWorker;
use PulseIndex\Laravel\PulseIndexServiceProvider;
use PulseIndex\Laravel\PulseSync;
use PulseIndex\Laravel\Reconciler;
use PulseIndex\Tests\Concerns\CreatesOutboxTable;
use PulseIndex\Tests\Fixtures\Property;

/**
 * Requires a live engine on PULSEINDEX_HOST.
 */
final class ReconcileLiveTest extends TestCase
{
    use CreatesOutboxTable;

    private string $tenant;

    protected function getPackageProviders($app): array
    {
        return [PulseIndexServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->tenant = 'php-recon-' . bin2hex(random_bytes(4));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('pulseindex.host', getenv('PULSEINDEX_HOST') ?: 'localhost:50051');
        $app['config']->set('pulseindex.api_key', getenv('PULSEINDEX_API_KEY') ?: 'dev-key');
        $app['config']->set('pulseindex.tenant_id', $this->tenant);
        $app['config']->set('pulseindex.outbox.dispatch', false);
        $app['config']->set('pulseindex.outbox.connections', ['testing']);
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
        $this->createOutboxTable();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('grpc')) {
            self::markTestSkipped('ext-grpc not loaded');
        }
        try {
            $this->app->make(Client::class)->getRecoveryState();
        } catch (\Throwable $e) {
            self::markTestSkipped('engine unreachable: ' . $e->getMessage());
        }
    }

    public function test_reconcile_repairs_a_bypassed_delete_and_insert(): void
    {
        /** @var ClientInterface $client */
        $client = $this->app->make(ClientInterface::class);
        $worker = new OutboxWorker($client);
        $reconciler = new Reconciler($client);

        // 3 rows in sync via the normal path.
        $ids = [];
        PulseSync::withoutSyncing(function () use (&$ids): void {
            foreach ([1, 2, 3] as $p) {
                $ids[] = Property::query()->create(['status' => 'open', 'price' => $p, 'tags' => ['feature:recon']])->id;
            }
        });
        \PulseIndex\Laravel\Outbox::enqueueMany(Property::query()->get());
        $worker->drain(null, 100, 12);

        // Drift, bypassing the observer entirely:
        DB::table('properties')->where('id', $ids[0])->delete();                       // orphan in engine
        $extra = DB::table('properties')->insertGetId(['status' => 'open', 'price' => 9, 'tags' => json_encode(['feature:recon']), 'created_at' => now(), 'updated_at' => now()]);

        $diff = $reconciler->plan(Property::class, $this->tenant);
        self::assertContains($ids[0], $diff->orphanIds);
        self::assertContains($extra, $diff->missingIds);

        $reconciler->apply($diff);
        $worker->drain(null, 100, 12);

        $hits = $client->search($client->query()->tenant($this->tenant)->must('feature:recon')->limit(50))->matchedEntityIds;
        self::assertNotContains($ids[0], $hits, 'orphan removed');
        self::assertContains($extra, $hits, 'bypassed insert now indexed');

        // cleanup
        foreach (array_merge($ids, [$extra]) as $id) {
            try {
                $client->deleteEntity($id, $this->tenant);
            } catch (\Throwable) {
            }
        }
    }
}
