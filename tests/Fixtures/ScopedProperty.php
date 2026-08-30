<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use PulseIndex\Laravel\PulseSearchable;

/**
 * A searchable model whose reconcile scope mirrors a `shouldBePulseSearchable`
 * rule ("draft rows are not indexed").
 */
final class ScopedProperty extends Model
{
    use PulseSearchable;

    protected $table = 'properties';

    protected $guarded = [];

    protected $casts = ['price' => 'integer'];

    public function shouldBePulseSearchable(): bool
    {
        return $this->status !== 'draft';
    }

    public function pulseReconcileScope($query, string $tenant)
    {
        return $query->where('status', '!=', 'draft');
    }
}
