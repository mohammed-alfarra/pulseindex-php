<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Unit\Laravel;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PulseIndex\Laravel\Outbox;
use PulseIndex\Laravel\OutboxWorker;
use PulseIndex\Laravel\PulseIndexServiceProvider;
use PulseIndex\Laravel\PulseSync;
use PulseIndex\Tests\Concerns\CreatesOutboxTable;
use PulseIndex\Tests\Fixtures\Property;

final class OutboxTest extends TestCase
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
        $app['config']->set('database.connections.secondary', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $app['config']->set('pulseindex.tenant_id', 'acme');
        $app['config']->set('pulseindex.outbox.dispatch', false);
        $app['config']->set('pulseindex.outbox.connections', ['testing', 'secondary']);
    }

    protected function defineDatabaseMigrations(): void
    {
        foreach (['testing', 'secondary'] as $conn) {
            Schema::connection($conn)->create('properties', function (Blueprint $table): void {
                $table->id();
                $table->string('status')->nullable();
                $table->unsignedInteger('price')->default(0);
                $table->json('tags')->nullable();
                $table->float('latitude')->nullable();
                $table->float('longitude')->nullable();
                $table->timestamps();
            });
            $this->createOutboxTable($conn);
        }
    }

    public function test_enqueue_many_inserts_ignoring_existing_markers(): void
    {
        PulseSync::withoutSyncing(function (): void {
            for ($i = 1; $i <= 5; $i++) {
                Property::query()->create(['status' => 'open', 'price' => $i]);
            }
        });

        $n = Outbox::enqueueMany(Property::query()->get());
        self::assertSame(5, $n);
        self::assertSame(5, DB::table('pulseindex_outbox')->count());

        // second run: rows already present -> insertOrIgnore leaves them
        Outbox::enqueueMany(Property::query()->get());
        self::assertSame(5, DB::table('pulseindex_outbox')->count());
    }

    public function test_pending_and_failed_counts_span_configured_connections(): void
    {
        $conns = ['testing', 'secondary'];

        DB::connection('testing')->table('pulseindex_outbox')->insert($this->row('A', 1));
        DB::connection('secondary')->table('pulseindex_outbox')->insert($this->row('B', 2));
        DB::connection('secondary')->table('pulseindex_outbox')->insert(
            $this->row('C', 3) + ['failed_at' => Carbon::now()],
        );

        self::assertSame(2, Outbox::pending($conns));
        self::assertSame(1, Outbox::failed($conns));
    }

    public function test_marker_lands_on_the_models_own_connection(): void
    {
        $onSecondary = new Property();
        $onSecondary->setConnection('secondary');
        $onSecondary->fill(['status' => 'open', 'price' => 10]);
        $onSecondary->save();

        self::assertSame(0, DB::connection('testing')->table('pulseindex_outbox')->count());
        self::assertSame(1, DB::connection('secondary')->table('pulseindex_outbox')->count());

        $worker = new OutboxWorker($this->createMock(\PulseIndex\ClientInterface::class));
        self::assertSame(0, $worker->drain('testing', 100, 12)['claimed']);
        self::assertSame(1, $worker->drain('secondary', 100, 12)['claimed']);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $key, int $entityId): array
    {
        $now = Carbon::now();

        return [
            'model_type' => Property::class,
            'model_key' => $key,
            'entity_id' => $entityId,
            'tenant_id' => 'acme',
            'operation' => 'upsert',
            'revision' => 0,
            'attempts' => 0,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
