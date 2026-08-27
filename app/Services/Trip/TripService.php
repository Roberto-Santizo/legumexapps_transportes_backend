<?php

namespace App\Services\Trip;

use App\Enums\LocationType;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Enums\VehicleStatus;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Trip\TripServiceInterface;
use App\Models\Client;
use App\Models\DeparturePoint;
use App\Models\Location;
use App\Models\ShippingLine;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Override;

class TripService implements TripServiceInterface
{
    /**
     * Smallest page size accepted, so nobody sweeps the table row by row.
     */
    private const MIN_PER_PAGE = 10;

    /**
     * Largest page size accepted, so nobody asks for the whole table at once.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * The eight relations every read carries, so a listing never falls into N+1.
     */
    private const RELATIONS = [
        'client', 'shippingLine', 'departurePoint', 'location',
        'pilot', 'vehicle', 'assignedBy', 'registeredBy',
    ];

    #[Override]
    public function getTrips(User $user, array $filters): LengthAwarePaginator|Collection
    {
        throw new BadRequestError('Pendiente: paso 4 de la SPEC 24');
    }

    #[Override]
    public function getTripById(User $user, int $id): Trip
    {
        throw new BadRequestError('Pendiente: paso 4 de la SPEC 24');
    }

    #[Override]
    public function create(User $user, array $data): Trip
    {
        throw new BadRequestError('Pendiente: paso 5 de la SPEC 24');
    }

    #[Override]
    public function update(int $id, array $data): Trip
    {
        throw new BadRequestError('Pendiente: paso 5 de la SPEC 24');
    }

    #[Override]
    public function destroy(int $id): Trip
    {
        throw new BadRequestError('Pendiente: paso 5 de la SPEC 24');
    }

    #[Override]
    public function assign(User $user, int $id, array $data): Trip
    {
        throw new BadRequestError('Pendiente: paso 6 de la SPEC 24');
    }

    #[Override]
    public function start(User $user, int $id): Trip
    {
        throw new BadRequestError('Pendiente: paso 6 de la SPEC 24');
    }

    #[Override]
    public function finish(User $user, int $id): Trip
    {
        throw new BadRequestError('Pendiente: paso 6 de la SPEC 24');
    }

    /**
     * Resolve the trip a write is aiming at, refusing one that has already been deleted.
     *
     * The only place in the domain that looks at the deleted rows, and the reason this
     * service can tell "this trip never existed" (404) from "this trip is gone" (400).
     * Shared guard of update(), destroy(), assign(), start() and finish(), so every one
     * of the five writing routes answers 400 —never 404— on a deleted trip.
     *
     * Throws a NotFoundError when the trip does not exist and a BadRequestError when it
     * has already been deleted.
     */
    private function resolveWritableTrip(int $id): Trip
    {
        /** Con los borrados a la vista: sin ellos, el segundo DELETE solo podría ser un 404. */
        $trip = Trip::withTrashed()->with(self::RELATIONS)->find($id);

        if ($trip === null) {
            throw new NotFoundError('El viaje no existe');
        }

        if ($trip->trashed()) {
            throw new BadRequestError('El viaje ya fue eliminado');
        }

        return $trip;
    }

    /**
     * Refuse a trip pointing at a catalog row that cannot back it any more.
     *
     * The four ids arrive already resolved, so the caller decides what "the trip's
     * catalogs" means: create() passes the payload, update() passes the payload merged
     * over the stored values, which is why editing a single date still revalidates the
     * four of them.
     *
     * Two of the checks exist because Laravel's `exists:` rule reads the table straight,
     * with no global scope: a soft deleted client or shipping line passes validation and
     * only this guard stops it. The other two carry rules the schema deliberately does
     * not: SPEC 21 left `type` freely editable, so a port may stop being one at any
     * moment and a database constraint would turn that PATCH into an integrity error.
     *
     * Throws a BadRequestError, each one with its own message, for every case.
     *
     * @param  array{client_id: int, shipping_line_id: int, departure_point_id: int, location_id: int}  $catalogs
     */
    private function ensureCatalogsAreUsable(array $catalogs): void
    {
        $client = Client::withTrashed()->find($catalogs['client_id']);

        if ($client === null || $client->trashed()) {
            throw new BadRequestError('El cliente seleccionado fue eliminado');
        }

        $shippingLine = ShippingLine::withTrashed()->find($catalogs['shipping_line_id']);

        if ($shippingLine === null || $shippingLine->trashed()) {
            throw new BadRequestError('La naviera seleccionada fue eliminada');
        }

        $location = Location::find($catalogs['location_id']);

        if ($location === null || $location->type !== LocationType::Port) {
            throw new BadRequestError('El destino seleccionado no es un puerto');
        }

        if ($location->status !== true) {
            throw new BadRequestError('El puerto de destino está inactivo');
        }

        $departurePoint = DeparturePoint::find($catalogs['departure_point_id']);

        if ($departurePoint === null || $departurePoint->status !== true) {
            throw new BadRequestError('El punto de partida está inactivo');
        }
    }

    /**
     * Refuse a crew that cannot take a trip.
     *
     * The pilot has to be a user with the pilot role **and** linked to a company, the
     * vehicle has to be active —`inactive` and `under_repair` are both refused— and the
     * two of them have to belong to the same company: a trip is driven by one crew, not
     * by a pilot of one carrier in the truck of another.
     *
     * Throws a BadRequestError, each one with its own message, for every case.
     */
    private function ensureCrewIsAssignable(int $pilotId, int $vehicleId): void
    {
        $pilot = User::find($pilotId);

        if ($pilot === null || $pilot->role !== UserRole::Pilot) {
            throw new BadRequestError('El usuario seleccionado no es un piloto');
        }

        $pilotCarrier = $pilot->currentCarrier();

        if ($pilotCarrier === null) {
            throw new BadRequestError('El piloto seleccionado no pertenece a ninguna empresa transportista');
        }

        $vehicle = Vehicle::find($vehicleId);

        if ($vehicle === null || $vehicle->status !== VehicleStatus::Active) {
            throw new BadRequestError('El vehículo seleccionado no está activo');
        }

        if ($vehicle->carrier_id !== $pilotCarrier->id) {
            throw new BadRequestError('El piloto y el vehículo deben pertenecer a la misma empresa transportista');
        }
    }

    /**
     * Refuse a reader that falls outside the trip's scope.
     *
     * The matrix the whole domain turns on, and the object counterpart of the scope the
     * listing applies as a SQL condition: an administrator and a manager reach every
     * trip; a pilot reaches only his own, never the pool; anybody else reaches the pool
     * of untaken trips plus whatever their own company assigned.
     *
     * It answers 403 and not 404 on purpose: the scope hides rows from a listing, it
     * does not pretend they were never published.
     *
     * Throws a ForbiddenError when the user is out of scope.
     */
    private function ensureUserCanSeeTrip(User $user, Trip $trip): void
    {
        if (in_array($user->role, [UserRole::Administrator, UserRole::Manager], true)) {
            return;
        }

        if ($user->role === UserRole::Pilot) {
            if ($trip->pilot_id === $user->id) {
                return;
            }

            throw new ForbiddenError('No puedes acceder a un viaje que no tienes asignado');
        }

        /** La bolsa: un viaje pendiente que nadie ha tomado es visible para cualquier empresa. */
        if ($this->isInThePool($trip)) {
            return;
        }

        $carrier = $user->currentCarrier();

        if ($carrier !== null && $this->resolveAssignerCarrierId($trip) === $carrier->id) {
            return;
        }

        throw new ForbiddenError('No puedes acceder a un viaje que no pertenece a tu empresa transportista');
    }

    /**
     * Tell whether nobody has taken the trip yet.
     *
     * The three crew columns are written together, so any of them would do; all three
     * are checked because the pool is what a carrier is allowed to see, and a half
     * filled row must never fall into it.
     */
    private function isInThePool(Trip $trip): bool
    {
        return $trip->status === TripStatus::Pending
            && $trip->pilot_id === null
            && $trip->vehicle_id === null;
    }

    /**
     * Resolve the company that took the trip, null while nobody has.
     *
     * `assigned_by` stores the **user** that assigned, for the sake of the audit trail;
     * every scope check goes through this method so the comparison lands on the
     * **company** instead, and the trip does not fall out of sight when that person
     * leaves the company.
     */
    private function resolveAssignerCarrierId(Trip $trip): ?int
    {
        return $trip->assignedBy?->currentCarrier()?->id;
    }

    /**
     * Resolve the page size requested by the client.
     *
     * A missing or non numeric limit means "do not paginate"; a numeric one is clamped
     * to [10, 100].
     */
    private function resolvePerPage(?string $limit): ?int
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }
}
