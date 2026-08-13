<?php

namespace App\Models;

use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The `area` column is deliberately left out of the fillable list: it is not an assignable
 * scalar, it goes in as a PostGIS expression and comes back as GeoJSON.
 */
#[Fillable(['name', 'description', 'color', 'status', 'registered_by'])]
class Zone extends Model
{
    /** @use HasFactory<ZoneFactory> */
    use HasFactory;

    /**
     * The spatial reference system every polygon of this project is stored in.
     */
    public const SRID = 4326;

    /**
     * The administrator that captured this zone.
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Normalize a zone name: trim it, collapse inner whitespace and upper case it.
     *
     * Shared by the form requests, which need it before the unique rule runs, and by
     * the service, which applies it right before persisting.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
    }

    /**
     * Turn `[lat, lng]` pairs describing an open ring into PostGIS WKT.
     *
     * Every pair is flipped to `lng lat` and the first point is repeated at the end to
     * close the ring. Together with `geoJsonToPairs()` this is the only place in the
     * project that knows about either rule.
     *
     * @param  array<int, array{0: float|string, 1: float|string}>  $pairs
     */
    public static function pairsToWkt(array $pairs): string
    {
        $ring = array_map(
            static fn (array $pair): string => (float) $pair[1].' '.(float) $pair[0],
            array_values($pairs),
        );

        $ring[] = $ring[0];

        return 'POLYGON(('.implode(', ', $ring).'))';
    }

    /**
     * Turn the GeoJSON produced by PostGIS back into `[lat, lng]` pairs with an open ring.
     *
     * Flips every point back to `lat, lng` and drops the repeated closing point. Returns an
     * empty array for anything it cannot read, so a malformed row never becomes a 500.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    public static function geoJsonToPairs(string $geoJson): array
    {
        $decoded = json_decode($geoJson, true);

        $ring = is_array($decoded) ? ($decoded['coordinates'][0] ?? null) : null;

        if (! is_array($ring) || count($ring) < 4) {
            return [];
        }

        array_pop($ring);

        return array_map(
            static fn (array $point): array => [(float) $point[1], (float) $point[0]],
            array_values($ring),
        );
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }
}
