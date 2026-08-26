<?php

namespace App\Models;

use App\Enums\LocationType;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single point destination, anchored to a real place by its Google place id.
 *
 * Every column is fillable: unlike `Zone`, this table has no PostGIS geometry that would
 * have to travel in as a SQL expression.
 */
#[Fillable(['name', 'description', 'type', 'google_place_id', 'latitude', 'longitude', 'status', 'registered_by'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    /**
     * The administrator that captured this location.
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Normalize a location name: trim it, collapse inner whitespace and upper case it.
     *
     * Shared by the form requests, which need it before the unique rule runs, and by
     * the service, which applies it right before persisting.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'status' => 'boolean',
        ];
    }
}
