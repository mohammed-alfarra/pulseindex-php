<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit\Laravel;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PulseIndex\ClientInterface;
use PulseIndex\Laravel\PulseIndexServiceProvider;
use PulseIndex\Laravel\PulseSync;
use PulseIndex\Laravel\Reconciler;
use PulseIndex\QueryBuilder;
use PulseIndex\SearchResult;
use PulseIndex\Tests\Concerns\CreatesOutboxTable;
use PulseIndex\Tests\Fixtures\Property;
use PulseIndex\Tests\Fixtures\ScopedProperty;

final class ReconcilerTest extends TestCase
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
        $app['config']->set('pulseindex.outbox.connections', ['testing']);
        $app['config']->set('pulseindex.reconcile.page_size', 2);
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
    }

    /**
     * @param list<int> $ids
     */
    private function engineReturns(array $ids): void
    {
        $this->client->method('search')->willReturnCallback(function (QueryBuilder $q) use ($ids): SearchResult {
            $req = $q->toRequest();
            $page = $req->getLimit();
            $slice = array_slice($ids, $req->getOffset(), $page);

            return new SearchResult(matchedEntityIds: array_values($slice), totalMatches: count($ids), executionTimeUs: 1);
        });
    }

    private function seedProps(int $n): void
    {
        PulseSync::withoutSyncing(function () use ($n): void {
            for ($i = 1; $i <= $n; $i++) {
                Property::query()->create(['status' => 'open', 'price' => $i]);
            }
        });
    }

    public function test_in_sync_produces_no_markers(): void
    {
        $this->seedProps(3);
        $this->engineReturns([1, 2, 3]);

        $diff = (new Reconciler($this->client))->plan(Property::class, 'acme');

        self::assertTrue($diff->inSync());
        self::assertSame(3, $diff->dbCount);
        self::assertSame(3, $diff->engineCount);
    }

    public function test_missing_rows_are_diffed_and_enqueued(): void
    {
        $this->seedProps(5);
        $this->engineReturns([1, 2, 3]);   // 4, 5 missing

        $reconciler = new Reconciler($this->client);
        $diff = $reconciler->plan(Property::class, 'acme');
        self::assertEqualsCanonicalizing([4, 5], $diff->missingIds);
        self::assertSame([], $diff->orphanIds);

        $reconciler->apply($diff);
        self::assertEqualsCanonicalizing(
            ['4', '5'],
            DB::table('pulseindex_outbox')->where('operation', 'upsert')->pluck('model_key')->all(),
        );
    }

    public function test_orphans_are_diffed_and_enqueued_as_deletes(): void
    {
        $this->seedProps(3);
        $this->engineReturns([1, 2, 3, 7, 9]);   // 7, 9 orphaned

        $reconciler = new Reconciler($this->client);
        $diff = $reconciler->plan(Property::class, 'acme');
        self::assertSame([], $diff->missingIds);
        self::assertEqualsCanonicalizing([7, 9], $diff->orphanIds);

        $reconciler->apply($diff);
        $rows = DB::table('pulseindex_outbox')->where('operation', 'delete')->get();
        self::assertCount(2, $rows);
        self::assertEqualsCanonicalizing([7, 9], $rows->pluck('entity_id')->map('intval')->all());
        self::assertSame(['acme', 'acme'], $rows->pluck('tenant_id')->all());
    }

    public function test_paging_collects_every_engine_id(): void
    {
        $this->seedProps(0);
        $this->engineReturns([10, 20, 30, 40, 50]);   // page_size = 2 -> 3 pages

        $ids = iterator_to_array((new Reconciler($this->client))->engineIds('acme'));
        self::assertSame([10, 20, 30, 40, 50], $ids);
    }

    public function test_reconcile_scope_override_prevents_false_orphans(): void
    {
        PulseSync::withoutSyncing(function (): void {
            ScopedProperty::query()->create(['id' => 1, 'status' => 'open', 'price' => 1]);
            ScopedProperty::query()->create(['id' => 2, 'status' => 'draft', 'price' => 2]);
        });
        // The engine legitimately holds only the non-draft row.
        $this->engineReturns([1]);

        $diff = (new Reconciler($this->client))->plan(ScopedProperty::class, 'acme');

        self::assertTrue($diff->inSync(), 'draft row must not count as missing or orphan');
    }
}
