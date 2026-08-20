<?php

namespace App\Models;

use App\Enums\AccessoryStatus;
use Database\Factories\AccessoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single physical accessory held in the national inventory.
 *
 * One row is one unit: two identical tires are two rows with two different codes,
 * so there is no `quantity` column. `price` is what the unit cost in GTQ and
 * `annual_depreciation` is the yearly straight-line depreciation rate as a
 * percentage. The depreciated value is never stored: `AccessoryResource` derives
 * it on every read.
 */
#[Fillable([
    'name', 'code', 'description', 'price',
    'purchase_date', 'annual_depreciation', 'status', 'registered_by',
])]
class Accessory extends Model
{
    /** @use HasFactory<AccessoryFactory> */
    use HasFactory;

    /**
     * The administrator that captured this accessory.
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Normalize an accessory name: trim it, collapse inner whitespace and upper case it.
     *
     * Shared by the form requests, which need it before the unique rule runs, and by
     * the service, which applies it right before persisting.
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
    }

    /**
     * Normalize an accessory code: trim it and upper case it.
     *
     * Sibling of `normalizeName()`, but it deliberately does **not** collapse inner
     * whitespace: a code is an identifier, not a phrase, and `A 100` and `A100` are
     * two different codes that must not be merged.
     */
    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AccessoryStatus::class,
            'price' => 'decimal:2',
            'annual_depreciation' => 'decimal:2',
            'purchase_date' => 'date',
        ];
    }
}
