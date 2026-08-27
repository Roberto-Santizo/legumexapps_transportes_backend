<?php

namespace App\Models;

use App\Enums\TripStatus;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An export trip: the row that ties a client, a shipping line, a departure point
 * and a destination port together.
 *
 * The table with the most foreign keys of the project, and the first transactional
 * one. A trip is born owned by nobody: `pilot_id`, `vehicle_id` and `assigned_by`
 * are filled together — or not at all — when a carrier company takes it. There is
 * no `carrier_id` column: the company is always derived from `assigned_by`.
 *
 * `recolection_date` and `ship_date` are planned at creation time and always lie in
 * the future; `start_date` and `end_date` are execution marks written by the server
 * on `/start` and `/finish`, never taken from the body.
 *
 * `polyline` holds the encoded route the frontend already resolved through
 * `GET /api/places/directions`: the API never calls Google, and it never recomputes
 * the route when the trip is edited.
 */
#[Fillable([
    'order', 'client_id', 'shipping_line_id', 'departure_point_id', 'location_id',
    'destination', 'container', 'transport',
    'recolection_date', 'ship_date', 'start_date', 'end_date',
    'polyline', 'observations', 'status',
    'pilot_id', 'vehicle_id', 'assigned_by', 'registered_by',
])]
class Trip extends Model
{
    /** @use HasFactory<TripFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The client this trip is shipped for.
     *
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The shipping line that carries the container overseas.
     *
     * @return BelongsTo<ShippingLine, $this>
     */
    public function shippingLine(): BelongsTo
    {
        return $this->belongsTo(ShippingLine::class);
    }

    /**
     * The point the cargo is picked up from.
     *
     * @return BelongsTo<DeparturePoint, $this>
     */
    public function departurePoint(): BelongsTo
    {
        return $this->belongsTo(DeparturePoint::class);
    }

    /**
     * The destination port, always a location of type `port`.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * The pilot driving this trip, null while nobody has taken it.
     *
     * @return BelongsTo<User, $this>
     */
    public function pilot(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pilot_id');
    }

    /**
     * The vehicle assigned to this trip, null while nobody has taken it.
     *
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * The user that assigned the crew, null while nobody has taken the trip.
     *
     * It stores the user for the sake of the audit trail, but every scope check
     * compares the **company** that user belongs to: a trip must not fall out of
     * sight when the person who took it leaves the company.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * The administrator that published this trip.
     *
     * @return BelongsTo<User, $this>
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    /**
     * Normalize a commercial reference: trim it, collapse inner whitespace and upper case it.
     *
     * A single function shared by `order` and `container`, against the project's habit of
     * repeating `normalizeName()` per model. That repetition exists so catalogs that are
     * **different models** may diverge; here these are two fields of the **same** model
     * following the very same rule, and duplicating it would protect nothing.
     *
     * `destination`, `transport` and `observations` deliberately get none of this: they
     * keep the casing they were typed with, and only the form request trims them.
     */
    public static function normalizeReference(string $value): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TripStatus::class,
            'recolection_date' => 'datetime',
            'ship_date' => 'datetime',
            'start_date' => 'datetime',
            'end_date' => 'datetime',
        ];
    }
}
