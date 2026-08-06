<?php

namespace App\Models;

use App\Enums\FuelPriceStatus;
use App\Enums\FuelType;
use Database\Factories\FuelPriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['fuel_type', 'price', 'status', 'registered_by'])]
class FuelPrice extends Model
{
    /** @use HasFactory<FuelPriceFactory> */
    use HasFactory;

    /**
     * The administrator that captured this price.
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
            'status' => FuelPriceStatus::class,
            'price' => 'decimal:2',
        ];
    }
}
