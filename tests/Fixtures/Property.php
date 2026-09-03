<?php

declare(strict_types=1);

namespace PulseIndex\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use PulseIndex\Laravel\PulseSearchable;

final class Property extends Model
{
    use PulseSearchable;

    protected $table = 'properties';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tags' => 'array',
        'price' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    /**
     * Which column answers which attribute namespace when the engine is
     * unreachable. Without this the fallback has nothing to translate a tag
     * filter into, and refuses rather than guessing at a column name.
     *
     * @return array<string, string>
     */
    public function pulseFallbackMap(): array
    {
        return [
            'status' => 'status',
            'price' => 'price',
        ];
    }

    public function toPulseSearchableArray(): array
    {
        return [
            'categories' => $this->tags ?? [],
            'status' => $this->status,
            'price' => $this->price,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
