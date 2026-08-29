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
