<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable outbox for PulseIndex model sync.
 *
 * Run this on every connection listed in `pulseindex.outbox.connections`
 * (markers are written on each model's own connection for transactional atomicity).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->connections() as $connection) {
            Schema::connection($connection)->create('pulseindex_outbox', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('model_type');
                $table->string('model_key');
                $table->unsignedBigInteger('entity_id');
                $table->string('tenant_id')->default('');
                $table->string('operation', 16);
                $table->unsignedBigInteger('revision')->default(0);
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestamp('available_at')->useCurrent()->index();
                $table->timestamp('failed_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();

                $table->unique(['model_type', 'model_key']);
            });
        }
    }

    public function down(): void
    {
        foreach ($this->connections() as $connection) {
            Schema::connection($connection)->dropIfExists('pulseindex_outbox');
        }
    }

    /**
     * @return list<string|null>
     */
    private function connections(): array
    {
        $configured = (array) config('pulseindex.outbox.connections', []);
        if ($configured === []) {
            return [config('database.default')];
        }

        return array_values($configured);
    }
};
