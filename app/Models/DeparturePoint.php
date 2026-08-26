<?php

namespace App\Models;

use Database\Factories\DeparturePointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single point of departure, anchored to a real place by its Google place id.
 *
 * Declared copy of `Location`: same shape, same rules, no freight rates hanging from it.
 * Every column is fillable: unlike `Zone`, this table has no PostGIS geometry that would
 * have to travel in as a SQL expression.
 */
#[Fillable(['name', 'description', 'google_place_id', 'latitude', 'longitude', 'status', 'registered_by'])]
class DeparturePoint extends Model
{
    /** @use HasFactory<DeparturePointFactory> */
    use HasFactory;

    /**
     * The administrator that captured this departure point.
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Normalize a departure point name: trim it, collapse inner whitespace and upper case it.
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
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'status' => 'boolean',
        ];
    }
}
