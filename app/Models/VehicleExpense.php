<?php

namespace App\Models;

use App\Enums\VehicleExpenseCategory;
use App\Enums\VehicleExpenseNature;
use Database\Factories\VehicleExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A maintenance expense charged against a vehicle.
 *
 * `amount` is expressed in GTQ. `expense_date` is the day the expense happened,
 * which is not the same as the `created_at` timestamp telling when it was
 * captured. `category` and `nature` are independent axes: any category accepts
 * both a preventive and a corrective nature.
 */
#[Fillable([
    'vehicle_id', 'category', 'nature', 'amount',
    'expense_date', 'description', 'registered_by',
])]
class VehicleExpense extends Model
{
    /** @use HasFactory<VehicleExpenseFactory> */
    use HasFactory;

    /**
     * The vehicle this expense belongs to.
     *
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * The user that registered this expense.
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
            'category' => VehicleExpenseCategory::class,
            'nature' => VehicleExpenseNature::class,
            'amount' => 'decimal:2',
            'expense_date' => 'date',
        ];
    }
}
