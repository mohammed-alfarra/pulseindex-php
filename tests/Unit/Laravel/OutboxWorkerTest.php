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
use PulseIndex\Entity;
use PulseIndex\Laravel\Outbox;
use PulseIndex\Laravel\OutboxWorker;
use PulseIndex\Laravel\PulseIndexServiceProvider;
use PulseIndex\Laravel\PulseSync;
use PulseIndex\Tests\Concerns\CreatesOutboxTable;
use PulseIndex\Tests\Fixtures\Property;
use RuntimeException;

final class OutboxWorkerTest extends TestCase
{
    use CreatesOutboxTable;

    private ClientInterface $client;

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
        $app['config']->set('pulseindex.outbox.dispatch', false);
        $app['config']->set('pulseindex.outbox.lease_seconds', 300);
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
        $this->client = $this->createMock(ClientInterface::class);
        $this->app->instance(ClientInterface::class, $this->client);
        $this->app->instance(Client::class, $this->client);
    }

    private function worker(): OutboxWorker
    {
        return new OutboxWorker($this->client);
    }

    private function makeProperty(int $price = 100): Property
    {
        return PulseSync::withoutSyncing(fn () => Property::query()->create(['status' => 'open', 'price' => $price]));
    }

    public function test_drains_upserts_grouped_by_tenant_and_removes_the_rows(): void
    {
        $a = $this->makeProperty(100);
        $b = $this->makeProperty(200);
        Outbox::mark($a, 'upsert');
        Outbox::mark($b, 'upsert');

        $received = [];
        $this->client->method('batchIndex')->willReturnCallback(function (array $entities) use (&$received): int {
            foreach ($entities as $e) {
                $received[$e->entityId] = $e->price;
            }

            return count($entities);
        });

        $r = $this->worker()->drain(null, 100, 12);

        self::assertSame(2, $r['indexed']);
        self::assertSame([$a->id => 100, $b->id => 200], $received);
        self::assertSame(0, DB::table('pulseindex_outbox')->count());
    }

    public function test_missing_model_becomes_a_delete(): void
    {
        $p = $this->makeProperty();
        Outbox::mark($p, 'upsert');
        PulseSync::withoutSyncing(fn () => $p->delete());   // row gone, marker still there

        $deleted = null;
        $this->client->method('deleteEntity')->willReturnCallback(function (int $id, string $tenant) use (&$deleted): bool {
            $deleted = [$id, $tenant];

            return true;
        });

        $r = $this->worker()->drain(null, 100, 12);

        self::assertSame(1, $r['deleted']);
        self::assertSame([$p->id, 'acme'], $deleted);
        self::assertSame(0, DB::table('pulseindex_outbox')->count());
    }

    public function test_rpc_failure_keeps_the_row_and_backs_off_then_fails(): void
    {
        $p = $this->makeProperty();
        Outbox::mark($p, 'upsert');
        $this->client->method('batchIndex')->willThrowException(new RuntimeException('UNAVAILABLE'));

        $this->worker()->drain(null, 100, 2);
        $row = DB::table('pulseindex_outbox')->first();
        self::assertSame(1, (int) $row->attempts);
        self::assertNull($row->failed_at);
        self::assertStringContainsString('UNAVAILABLE', (string) $row->last_error);

        // Make it eligible again and burn the last attempt.
        DB::table('pulseindex_outbox')->update(['available_at' => Carbon::now()->subMinute()]);
        $this->worker()->drain(null, 100, 2);
        $row = DB::table('pulseindex_outbox')->first();
        self::assertSame(2, (int) $row->attempts);
        self::assertNotNull($row->failed_at);

        // failed rows are skipped
        DB::table('pulseindex_outbox')->update(['available_at' => Carbon::now()->subMinute()]);
        self::assertSame(0, $this->worker()->drain(null, 100, 2)['claimed']);
    }

    public function test_concurrent_update_during_the_rpc_is_not_lost(): void
    {
        $p = $this->makeProperty(100);
        Outbox::mark($p, 'upsert');

        $prices = [];
        $this->client->method('batchIndex')->willReturnCallback(function (array $entities) use (&$prices, $p): int {
            $prices[] = $entities[0]->price;
            if (count($prices) === 1) {
                // simulate a concurrent write landing while this RPC is "in flight"
                PulseSync::withoutSyncing(fn () => $p->update(['price' => 999]));
                Outbox::mark($p->refresh(), 'upsert');   // bumps revision
            }

            return count($entities);
        });

        // pass 1: pushes price 100, finalize sees a bumped revision -> row kept, re-queued
        $r1 = $this->worker()->drain(null, 100, 12);
        self::assertSame(1, $r1['requeued']);
        self::assertSame(1, DB::table('pulseindex_outbox')->count());

        // pass 2: pushes the current price 999, finalize deletes the row
        $r2 = $this->worker()->drain(null, 100, 12);
        self::assertSame(0, DB::table('pulseindex_outbox')->count());
        self::assertSame([100, 999], $prices);
    }

    public function test_lease_makes_a_crashed_claim_recoverable(): void
    {
        $p = $this->makeProperty();
        Outbox::mark($p, 'upsert');

        // "claim" the row by pushing available_at into the future, as drain() does.
        DB::table('pulseindex_outbox')->update(['available_at' => Carbon::now()->addSeconds(300)]);
        self::assertSame(0, $this->worker()->drain(null, 100, 12)['claimed'], 'leased row is invisible');

        Carbon::setTestNow(Carbon::now()->addSeconds(301));
        $this->client->method('batchIndex')->willReturn(1);
        self::assertSame(1, $this->worker()->drain(null, 100, 12)['claimed'], 'lease expired -> reclaimable');
        Carbon::setTestNow();
    }
}
