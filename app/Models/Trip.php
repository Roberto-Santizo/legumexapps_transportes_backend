<?php

namespace App\Models;

use App\Enums\TripStatus;
use Database\Factories\TripFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 *
 * `traveled_polyline` is the real route in the same encoded format: on `/finish`
 * the whole `trip_positions` trail is encoded in `recorded_at asc, id asc` order and
 * written here, overwriting whatever a previous finish left. It is derived data —
 * never accepted from a body — and `null` means the trip has not finished, finished
 * without a single position, or finished before the column existed; the API does not
 * tell those three apart.
 *
 * `estimated_kilometers` and `estimated_hours` are the distance and duration of that
 * planned route, taken from the same `GET /api/places/directions` response as
 * `polyline` and always sent together with it: the API never computes, recomputes nor
 * checks them against the line. Both are nullable without default, and `null` means
 * exactly one thing — the trip was created before SPEC 30 — because the API does not
 * let a new trip be created nor edited without them.
 *
 * `traveled_kilometers` and `traveled_hours` are the real counterparts of those two
 * estimates (SPEC 32), written **only on `/finish`** in the same `update()` as
 * `end_date` and `traveled_polyline`: the kilometers are the raw Haversine sum of
 * every consecutive pair of the `trip_positions` trail —no GPS-noise threshold, no
 * simplification— and the hours are `end_date - start_date`, stops included. Derived
 * data, never accepted from a body. Unlike `traveled_polyline`, `null` has a single
 * meaning here —the trip has not finished, or finished before SPEC 32—: a trip that
 * finished with zero or one position stores `0.00`, because zero is a legitimate
 * distance while an empty string is not a polyline.
 */
#[Fillable([
    'order', 'client_id', 'shipping_line_id', 'departure_point_id', 'location_id',
    'destination', 'container', 'transport',
    'recolection_date', 'ship_date', 'start_date', 'end_date',
    'polyline', 'estimated_kilometers', 'estimated_hours',
    'traveled_polyline', 'traveled_kilometers', 'traveled_hours', 'observations', 'status',
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
     * The fuel loads registered on this trip (SPEC 27).
     *
     * The only relation of the project that exists **for a sum and not for a payload**:
     * neither `TripResource` nor `TripListResource` exposes the rows, the same way
     * `Vehicle` never gained `expenses()` nor `Trip` gained `positions()`. It is here
     * because `totalFuelGallons` is resolved with `withSum` —restricted to the confirmed
     * loads— and that needs the relation to hang off.
     *
     * @return HasMany<TripFuel, $this>
     */
    public function fuels(): HasMany
    {
        return $this->hasMany(TripFuel::class);
    }

    /**
     * The travel allowances registered on this trip (SPEC 31).
     *
     * Same reason to exist as `fuels()`: neither Resource exposes the rows, but
     * `totalExpensesAmount` is resolved with `withSum` —restricted to the confirmed
     * allowances— and that needs the relation to hang off.
     *
     * @return HasMany<TripExpense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(TripExpense::class);
    }

    /**
     * The live track reported by the pilot (SPEC 26), in `recorded_at asc, id asc` order.
     *
     * Only `GET /api/trips/{trip}` loads it, so the listing never drags the trail along.
     *
     * @return HasMany<TripPosition, $this>
     */
    public function positions(): HasMany
    {
        return $this->hasMany(TripPosition::class)->orderBy('recorded_at')->orderBy('id');
    }

    /**
     * The finished product lines this trip carries (SPEC 37).
     *
     * Only the guards use it —the `clientId` change on `PATCH` and the «last line» on
     * `DELETE`—: neither `TripResource` nor `TripListResource` exposes the rows.
     *
     * @return HasMany<TripFinishedProduct, $this>
     */
    public function finishedProducts(): HasMany
    {
        return $this->hasMany(TripFinishedProduct::class);
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
