<?php

namespace App\Models;

use App\Enums\FuelType;
use Database\Factories\FreightRateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A freight rate is an open band: it rules from `fuel_min` upwards, until a higher band
 * of the same zone, product and fuel type exists.
 */
#[Fillable(['zone_id', 'product_id', 'fuel_type', 'fuel_min', 'price_per_pound', 'registered_by'])]
class FreightRate extends Model
{
    /** @use HasFactory<FreightRateFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The zone this rate quotes.
     *
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * The product this rate quotes.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The administrator that captured this rate.
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fuel_type' => FuelType::class,
            'fuel_min' => 'decimal:2',
            'price_per_pound' => 'decimal:6',
        ];
    }
}
