<?php

namespace App\Models;

use Database\Factories\CarrierPilotSalaryHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per real change of a pilot's monthly base salary, in GTQ. It is written only
 * by the API, inside the same transaction that moves `carrier_pilots.salary`, and it is
 * never edited nor deleted: `created_at` is the date the change took effect.
 */
#[Fillable(['carrier_pilot_id', 'previous_salary', 'new_salary', 'changed_by'])]
class CarrierPilotSalaryHistory extends Model
{
    /** @use HasFactory<CarrierPilotSalaryHistoryFactory> */
    use HasFactory;

    /**
     * The pilot link whose salary changed.
     *
     * @return BelongsTo<CarrierPilot, $this>
     */
    public function carrierPilot(): BelongsTo
    {
        return $this->belongsTo(CarrierPilot::class);
    }

    /**
     * The authenticated user that made the change.
     *
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            /** Null on the first assignment: there was no salary before it. */
            'previous_salary' => 'decimal:2',
            'new_salary' => 'decimal:2',
        ];
    }
}
