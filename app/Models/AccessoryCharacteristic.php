<?php

namespace App\Models;

use Database\Factories\AccessoryCharacteristicFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A free-form name/value pair describing one accessory of the national inventory.
 *
 * The set of fields is not fixed by the schema: two accessories can be described
 * with completely different lists without migrating anything. There is no catalog
 * of allowed names and no declared type — the value is always text.
 */
#[Fillable(['accessory_id', 'name', 'value', 'registered_by'])]
class AccessoryCharacteristic extends Model
{
    /** @use HasFactory<AccessoryCharacteristicFactory> */
    use HasFactory;

    /**
     * The accessory this characteristic describes.
     *
     * @return BelongsTo<Accessory, $this>
     */
    public function accessory(): BelongsTo
    {
        return $this->belongsTo(Accessory::class);
    }

    /**
     * The user that captured this characteristic.
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Normalize a characteristic name: trim it, collapse inner whitespace and upper case it.
     *
     * A copy of `Accessory::normalizeName()`, not a call to it: each model owns its
     * normalization and may diverge without dragging the others along.
     *
     * Shared by the form requests, which need it before the unique rule runs, and by
     * the service, which applies it right before persisting.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
    }

    /**
     * Normalize a characteristic value: trim it, and nothing else.
     *
     * Deliberately asymmetric with `normalizeName()`. The name is an identifier, so
     * collapsing and upper casing it makes uniqueness case-insensitive without relying
     * on the collation; the value is user content and upper casing it would ruin it
     * («Diésel» is not «DIÉSEL»).
     */
    public static function normalizeValue(string $value): string
    {
        return trim($value);
    }
}
