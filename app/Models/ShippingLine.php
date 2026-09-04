<?php

namespace App\Models;

use Database\Factories\ShippingLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A shipping line of the national catalog: a name, and nothing else.
 *
 * The first domain of the project with a single business field, below
 * `AccessoryCharacteristic` and `Client`, which own two. It is the reduced twin of
 * `Client`: same soft deleting, same role split and same listing, minus the `code`.
 *
 * `DELETE` soft deletes the row, it disappears from the API and it never comes back —
 * while still holding on to its `name`, which the unique index keeps reserved forever.
 *
 * Since SPEC 24 something does hang off a shipping line: `trips.shipping_line_id`,
 * which is why the service refuses to delete one that still has trips.
 */
#[Fillable(['name', 'registered_by'])]
class ShippingLine extends Model
{
    /** @use HasFactory<ShippingLineFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The administrator that captured this shipping line.
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * The export trips carried by this shipping line.
     *
     * The only relation this catalog owns, added by SPEC 24. Its single reader is the
     * guard in the service, which counts them —deleted ones included— before allowing a
     * deletion: a soft deleted shipping line would vanish from the API while its trips
     * kept showing the name of somebody nobody can look up any more.
     *
     * @return HasMany<Trip, $this>
     */
    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    /**
     * Normalize a shipping line name: trim it, collapse inner whitespace and upper case it.
     *
     * A copy of the rule `Product`, `Location`, `Accessory` and `Client` already own, not
     * a call to any of them: each catalog keeps its own normalization and may diverge
     * without dragging the others along.
     *
     * Shared by the form requests, which normalize before validating, and by the service,
     * which applies it right before checking uniqueness and persisting.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
    }
}
