<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit\Laravel;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PulseIndex\Client;
use PulseIndex\ClientInterface;
use PulseIndex\Engine\V1\FilterPredicate\Operation;
use PulseIndex\Exception\GrpcException;
use PulseIndex\Geo\GeoHash;
use PulseIndex\Laravel\PulseIndexServiceProvider;
use PulseIndex\Laravel\PulseQueryBuilder;
use PulseIndex\QueryBuilder;
use PulseIndex\SearchResult;
use PulseIndex\Tests\Concerns\CreatesOutboxTable;
use PulseIndex\Tests\Fixtures\Property;

final class PulseSearchableTest extends TestCase
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
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('pulseindex.host', 'localhost:50051');
        $app['config']->set('pulseindex.api_key', 'dev-key');
        $app['config']->set('pulseindex.timeout', 5);
        $app['config']->set('pulseindex.fallback_enabled', true);
        $app['config']->set('pulseindex.tenant_id', 'acme');
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

        config()->set('pulseindex.outbox.dispatch', false);

        $client = $this->createMock(ClientInterface::class);
        $this->app->instance(ClientInterface::class, $client);
        $this->app->instance(Client::class, $client);
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function outboxRows(): \Illuminate\Support\Collection
    {
        return \Illuminate\Support\Facades\DB::table('pulseindex_outbox')->get();
    }

    public function testPulseSearchReturnsQueryBuilder(): void
    {
        $builder = Property::pulseSearch();

        self::assertInstanceOf(PulseQueryBuilder::class, $builder);
    }

    public function testCreatedObserverEnqueuesAnOutboxMarker(): void
    {
        $property = Property::query()->create([
            'status' => 'open',
            'price' => 1500,
            'tags' => ['feature:pool'],
        ]);

        $rows = $this->outboxRows();
        self::assertCount(1, $rows);
        self::assertSame(Property::class, $rows[0]->model_type);
        self::assertSame((string) $property->id, $rows[0]->model_key);
        self::assertSame((int) $property->id, (int) $rows[0]->entity_id);
        self::assertSame('acme', $rows[0]->tenant_id);
        self::assertSame('upsert', $rows[0]->operation);
    }

    public function testRepeatedChangesCoalesceIntoOneMarker(): void
    {
        $property = Property::query()->create(['status' => 'open', 'price' => 100]);
        $property->update(['price' => 200]);
        $property->update(['price' => 300]);

        $rows = $this->outboxRows();
        self::assertCount(1, $rows);
        self::assertSame('upsert', $rows[0]->operation);
        self::assertGreaterThanOrEqual(2, (int) $rows[0]->revision);
        self::assertSame(0, (int) $rows[0]->attempts);

        $property->delete();

        $rows = $this->outboxRows();
        self::assertCount(1, $rows);
        self::assertSame('delete', $rows[0]->operation);
        self::assertSame((int) $property->id, (int) $rows[0]->entity_id);
    }

    public function testHydrationPreservesPulseRankOrder(): void
    {
        $this->seedProperties();

        $client = $this->createMock(ClientInterface::class);
        $client->method('search')->willReturn(new SearchResult(
            matchedEntityIds: [3, 1, 2],
            totalMatches: 3,
            executionTimeUs: 12,
        ));
        $this->app->instance(ClientInterface::class, $client);
        $this->app->instance(Client::class, $client);

        $models = Property::pulseSearch($client)
            ->where('status', 'open')
            ->get();

        self::assertSame([3, 1, 2], $models->pluck('id')->all());
    }

    public function testPaginateUsesPulseIdsAndTotals(): void
    {
        $this->seedProperties();

        $client = $this->createMock(ClientInterface::class);
        $client->method('search')->willReturnCallback(function (QueryBuilder $query): SearchResult {
            $data = $query->toArray();
            self::assertSame(2, $data['limit']);
            self::assertSame(0, $data['offset']);

            return new SearchResult(
                matchedEntityIds: [3, 1],
                totalMatches: 3,
                executionTimeUs: 8,
            );
        });
        $this->app->instance(ClientInterface::class, $client);
        $this->app->instance(Client::class, $client);

        $page = Property::pulseSearch($client)->paginate(2);

        self::assertSame([3, 1], $page->getCollection()->pluck('id')->all());
        self::assertSame(3, $page->total());
        self::assertSame(2, $page->perPage());
    }

    public function testFluentFiltersMapOntoPulseQuery(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $this->app->instance(ClientInterface::class, $client);
        $this->app->instance(Client::class, $client);

        $request = Property::pulseSearch($client)
            ->tenant('acme')
            ->where('feature:pool')
            ->where('status', 'open')
            ->whereIn('amenity', ['parking', 'gym'])
            ->whereRange('price', 100, 500)
            ->whereGeoRadius(42.6, -5.6, 4.9)
            ->limit(25)
            ->toPulseQuery()
            ->toRequest();

        self::assertSame('acme', $request->getTenantId());
        self::assertSame(25, $request->getLimit());

        $must = [];
        $should = [];
        foreach ($request->getFilters() as $filter) {
            if ($filter->getOp() === Operation::MUST) {
                $must[] = $filter->getAttribute();
            }
            if ($filter->getOp() === Operation::SHOULD) {
                $should[] = $filter->getAttribute();
            }
        }

        self::assertContains('feature:pool', $must);
        self::assertContains('status:open', $must);
        self::assertContains('amenity:parking', $should);
        self::assertContains('amenity:gym', $should);
        self::assertContains(GeoHash::tag('ezs42'), $should);
        self::assertCount(1, $request->getRanges());
        self::assertSame('price', $request->getRanges()[0]->getField());
        self::assertSame(100, $request->getRanges()[0]->getMinVal());
        self::assertSame(500, $request->getRanges()[0]->getMaxVal());
    }

    public function testFallbackToEloquentWhenPulseFails(): void
    {
        $this->seedProperties();

        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
            if ($event->level === 'warning') {
                $warnings[] = (string) $event->message;
            }
        });

        $client = $this->createMock(ClientInterface::class);
        $client->method('search')->willThrowException(new GrpcException(
            message: 'unavailable',
            grpcStatusCode: 14,
            grpcDetails: 'connection refused',
        ));
        $this->app->instance(ClientInterface::class, $client);
        $this->app->instance(Client::class, $client);

        $models = Property::pulseSearch($client)
            ->where('status', 'open')
            ->whereRange('price', 150, 400)
            ->get();

        self::assertSame([2, 3], $models->pluck('id')->all());
        self::assertNotEmpty($warnings);
        self::assertTrue(
            collect($warnings)->contains(fn (string $message) => str_contains($message, 'PulseIndex connection failed'))
        );
    }

    public function testFallbackDisabledRethrows(): void
    {
        config(['pulseindex.fallback_enabled' => false]);

        $client = $this->createMock(ClientInterface::class);
        $client->method('search')->willThrowException(new GrpcException(
            message: 'unavailable',
            grpcStatusCode: 14,
        ));
        $this->app->instance(ClientInterface::class, $client);
        $this->app->instance(Client::class, $client);

        $this->expectException(GrpcException::class);

        Property::pulseSearch($client)->get();
    }

    public function testServiceProviderMergesTimeoutAndFallbackConfig(): void
    {
        self::assertSame('localhost:50051', config('pulseindex.host'));
        self::assertSame('dev-key', config('pulseindex.api_key'));
        self::assertEquals(5, config('pulseindex.timeout'));
        self::assertTrue(config('pulseindex.fallback_enabled'));
    }

    public function testWithoutSyncingToPulseSkipsTheOutbox(): void
    {
        Property::withoutSyncingToPulse(function (): void {
            Property::query()->create([
                'status' => 'open',
                'price' => 10,
            ]);
        });

        self::assertSame(1, Property::query()->count());
        self::assertCount(0, $this->outboxRows());
    }

    private function seedProperties(): void
    {
        Property::withoutSyncingToPulse(function (): void {
            Property::query()->insert([
                ['id' => 1, 'status' => 'closed', 'price' => 100, 'created_at' => now(), 'updated_at' => now()],
                ['id' => 2, 'status' => 'open', 'price' => 200, 'created_at' => now(), 'updated_at' => now()],
                ['id' => 3, 'status' => 'open', 'price' => 300, 'created_at' => now(), 'updated_at' => now()],
            ]);
        });
    }
}
