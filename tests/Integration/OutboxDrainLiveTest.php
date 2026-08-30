<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Integration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PulseIndex\Client;
use PulseIndex\ClientInterface;
use PulseIndex\Exception\GrpcException;
use PulseIndex\Laravel\Outbox;
use PulseIndex\Laravel\OutboxWorker;
use PulseIndex\Laravel\PulseIndexServiceProvider;
use PulseIndex\Laravel\PulseSync;
use PulseIndex\Tests\Concerns\CreatesOutboxTable;
use PulseIndex\Tests\Fixtures\Property;

/**
 * Requires a live engine on PULSEINDEX_HOST. Drains real markers over gRPC.
 */
final class OutboxDrainLiveTest extends TestCase
{
    use CreatesOutboxTable;

    private string $tenant;

    protected function getPackageProviders($app): array
    {
        return [PulseIndexServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->tenant = 'php-outbox-' . bin2hex(random_bytes(4));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('pulseindex.host', getenv('PULSEINDEX_HOST') ?: 'localhost:50051');
        $app['config']->set('pulseindex.api_key', getenv('PULSEINDEX_API_KEY') ?: 'dev-key');
        $app['config']->set('pulseindex.tenant_id', $this->tenant);
        $app['config']->set('pulseindex.outbox.dispatch', false);
        $app['config']->set('pulseindex.outbox.connections', ['testing']);
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

    public function test_drain_pushes_to_a_live_engine_then_keeps_the_row_on_an_outage(): void
    {
        /** @var ClientInterface $client */
        $client = $this->app->make(ClientInterface::class);

        $p = PulseSync::withoutSyncing(fn () => Property::query()->create([
            'status' => 'open', 'price' => 4200, 'tags' => ['feature:outbox-probe'],
        ]));

        Outbox::mark($p, 'upsert');
        $r = (new OutboxWorker($client))->drain(null, 100, 12);

        self::assertSame(1, $r['indexed']);
        self::assertSame(0, DB::table('pulseindex_outbox')->count());

        $hits = $client->search(
            $client->query()->tenant($this->tenant)->must('feature:outbox-probe')->limit(10),
        );
        self::assertContains($p->id, $hits->matchedEntityIds);

        // Outage: a dead endpoint keeps the marker and increments attempts.
        $dead = new OutboxWorker(new Client(['host' => '127.0.0.1:1', 'api_key' => 'x']));
        Outbox::mark($p->refresh(), 'upsert');
        $dead->drain(null, 100, 12);

        $row = DB::table('pulseindex_outbox')->first();
        self::assertNotNull($row);
        self::assertSame(1, (int) $row->attempts);

        try {
            $client->deleteEntity($p->id, $this->tenant);
        } catch (GrpcException) {
        }
    }
}
