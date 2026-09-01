<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit\Laravel;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PulseIndex\Client;
use PulseIndex\ClientInterface;
use PulseIndex\Exception\PulseIndexException;
use PulseIndex\Laravel\PulseIndexServiceProvider;
use PulseIndex\Laravel\PulseSync;
use PulseIndex\Tests\Concerns\CreatesOutboxTable;
use PulseIndex\Tests\Fixtures\Property;
use RuntimeException;

final class ReindexCommandTest extends TestCase
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

    /** @param bool $serving false puts the service in the state --recovery requires. */
    private function fakeClient(bool $serving = true): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('servingStatus')->willReturn($serving ? 1 : 2);
        $this->app->instance(ClientInterface::class, $client);
        $this->app->instance(Client::class, $client);

        return $client;
    }



    private function makeRows(int $n): void
    {
        PulseSync::withoutSyncing(function () use ($n): void {
            for ($i = 1; $i <= $n; $i++) {
                Property::query()->create(['status' => 'open', 'price' => 100 + $i, 'tags' => ['feature:pool']]);
            }
        });
    }

    public function test_bootstrap_enqueues_and_drains(): void
    {
        $this->makeRows(3);
        $client = $this->fakeClient();

        $seen = 0;
        $client->method('batchIndex')->willReturnCallback(function (array $e) use (&$seen): int {
            $seen += count($e);

            return count($e);
        });

        $this->artisan('pulse:reindex', ['model' => Property::class])->assertExitCode(0);

        self::assertSame(3, $seen);
        self::assertSame(0, DB::table('pulseindex_outbox')->count());
    }

    public function test_async_only_enqueues(): void
    {
        $this->makeRows(2);
        $client = $this->fakeClient();
        $client->expects(self::never())->method('batchIndex');

        $this->artisan('pulse:reindex', ['model' => Property::class, '--async' => true])->assertExitCode(0);

        self::assertSame(2, DB::table('pulseindex_outbox')->count());
    }

    public function test_concurrent_write_during_reindex_is_not_lost(): void
    {
        $p = PulseSync::withoutSyncing(fn () => Property::query()->create(['status' => 'open', 'price' => 100]));
        $client = $this->fakeClient();

        $pushed = [];
        $client->method('batchIndex')->willReturnCallback(function (array $e) use (&$pushed, $p): int {
            $pushed[] = $e[0]->price;
            if (count($pushed) === 1) {
                PulseSync::withoutSyncing(fn () => $p->update(['price' => 777]));
                \PulseIndex\Laravel\Outbox::mark($p->refresh(), 'upsert');
            }

            return count($e);
        });

        $this->artisan('pulse:reindex', ['model' => Property::class])->assertExitCode(0);

        self::assertSame([100, 777], $pushed);
        self::assertSame(0, DB::table('pulseindex_outbox')->count());
    }
}
