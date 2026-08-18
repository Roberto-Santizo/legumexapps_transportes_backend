<?php

namespace App\Models;

use App\Enums\VehicleCondition;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vehicle owned by a carrier.
 *
 * Money columns are expressed in GTQ: `purchase_price` is what the vehicle cost
 * and `monthly_insurance_cost` is the insurance premium paid **per month**.
 * `kilometers_per_gallon` is fuel efficiency in km per gallon and `mileage` is
 * the odometer reading in whole kilometers.
 */
#[Fillable([
    'carrier_id', 'plate', 'brand', 'model', 'year', 'capacity', 'type',
    'condition', 'kilometers_per_gallon', 'purchase_price',
    'monthly_insurance_cost', 'mileage', 'engine_number',
    'image', 'status',
])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    /**
     * The company that owns this vehicle.
     *
     * @return BelongsTo<Carrier, $this>
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => VehicleType::class,
            'status' => VehicleStatus::class,
            'condition' => VehicleCondition::class,
            'capacity' => 'decimal:2',
            'kilometers_per_gallon' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'monthly_insurance_cost' => 'decimal:2',
            'mileage' => 'integer',
            'year' => 'integer',
        ];
    }
}
