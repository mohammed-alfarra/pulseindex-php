<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesOutboxTable
{
    protected function createOutboxTable(?string $connection = null): void
    {
        $schema = Schema::connection($connection);
        if ($schema->hasTable('pulseindex_outbox')) {
            return;
        }

        $schema->create('pulseindex_outbox', function (Blueprint $table): void {
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
