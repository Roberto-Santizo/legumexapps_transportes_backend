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
use App\Models\Carrier;
use App\Models\Client;
use App\Models\DeparturePoint;
use App\Models\Location;
use App\Models\ShippingLine;
use App\Models\Trip;
use App\Models\TripFuel;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Override;

class TripService implements TripServiceInterface
{
    /**
     * Smallest page size accepted.
     *
     * A one is deliberately **not** the ten the rest of the project uses: a page of
     * trips is a board the frontend paints whole, so asking for five has to give five
     * and not a silently rounded ten. The floor only rules out a zero or a negative
     * page size, which the paginator cannot honour at all.
     */
    private const MIN_PER_PAGE = 1;

    /**
     * Largest page size accepted, so nobody asks for the whole table at once.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * The eight relations a detail read carries, so painting a trip never falls into N+1.
     *
     * The pilot comes with its documents nested: `TripResource` paints `pilotDpiImage`
     * and `pilotLicenseImage` from them, and resolving those per trip would cost one
     * extra query each. The listing does not need them — `TripListResource` keeps its
     * 15 keys and paints no document.
     */
    private const RELATIONS = [
        'client', 'shippingLine', 'departurePoint', 'location',
        'pilot.pilotDocument', 'vehicle', 'assignedBy', 'registeredBy',
    ];

    /**
     * The six relations the listing actually paints: `TripListResource` names them and
     * drops `client` and `assignedBy`, so a page does not load what nobody reads.
     */
    private const LIST_RELATIONS = [
        'shippingLine', 'departurePoint', 'location',
        'pilot', 'vehicle', 'registeredBy',
    ];

    /**
     * The five listing filters that are a plain id, mapped to their column.
     */
    private const ID_FILTERS = [
        'clientId' => 'client_id',
        'shippingLineId' => 'shipping_line_id',
        'locationId' => 'location_id',
        'pilotId' => 'pilot_id',
        'vehicleId' => 'vehicle_id',
    ];

    /**
     * The only shape the two date filters are read in.
     */
    private const DATE_FORMAT = 'Y-m-d';

    /**
     * The four catalog foreign keys, revalidated on every write, mapped to their column.
     *
     * The body speaks camelCase and the table snake_case, so every write goes through one
     * of these maps instead of dumping the payload straight into the model: a key the
     * contract does not name simply has nowhere to land.
     */
    private const CATALOG_FIELDS = [
        'clientId' => 'client_id',
        'shippingLineId' => 'shipping_line_id',
        'departurePointId' => 'departure_point_id',
        'locationId' => 'location_id',
    ];

    /**
     * The two references normalized to upper case with collapsed inner whitespace.
     *
     * `destination`, `transport` and `observations` are deliberately not here: they keep
     * the casing they were typed with.
     */
    private const REFERENCE_FIELDS = ['order', 'container'];

    /**
     * The fields the administrator's general PATCH is allowed to write.
     *
     * Four are deliberately absent. `pilot_id` and `vehicle_id`, because assigning is
     * what /assignment is for and it belongs to the carrier, not to the administrator;
     * `assigned_by` and `registered_by`, because the two authors are never rewritten.
     * Sending any of them is ignored in silence with a 200, exactly like `vehicle_id`
     * on a vehicle expense.
     *
     * `status` **is** here, and it is not checked against any transition: a finished
     * trip may go back to pending keeping both of its execution dates.
     */
    private const UPDATABLE_FIELDS = [
        'order' => 'order',
        'clientId' => 'client_id',
        'shippingLineId' => 'shipping_line_id',
        'departurePointId' => 'departure_point_id',
        'locationId' => 'location_id',
        'destination' => 'destination',
        'container' => 'container',
        'transport' => 'transport',
        'recolectionDate' => 'recolection_date',
        'shipDate' => 'ship_date',
        'polyline' => 'polyline',
        'observations' => 'observations',
        'status' => 'status',
    ];

    #[Override]
    public function getTrips(User $user, array $filters): LengthAwarePaginator|Collection
    {
        /**
         * Sin withTrashed(): el scope del trait deja fuera a los borrados y no hay ningún
         * filtro que los devuelva. Para el listado, un viaje borrado no existe.
         */
        $query = Trip::query()->with(self::LIST_RELATIONS);

        /**
         * El ámbito va **antes** que los filtros del usuario, y no después: si se aplicara
         * al final, un `?status=pending` desde otra empresa revelaría los viajes que ya
         * tomó la primera. Cada filtro de aquí abajo solo puede recortar lo que el ámbito
         * ya dejó pasar.
         */
        $this->applyScope($query, $user);

        $status = isset($filters['status']) ? TripStatus::tryFrom($filters['status']) : null;

        if ($status !== null) {
            $query->where('status', '=', $status->value);
        }

        /** Los cinco filtros por id comparten regla: numérico se aplica, cualquier otra cosa se ignora. */
        foreach (self::ID_FILTERS as $filter => $column) {
            if (isset($filters[$filter]) && is_numeric($filters[$filter])) {
                $query->where($column, '=', (int) $filters[$filter]);
            }
        }

        $dateFrom = $this->normalizeDate($filters['dateFrom'] ?? null);

        if ($dateFrom !== null) {
            $query->whereDate('recolection_date', '>=', $dateFrom);
        }

        $dateTo = $this->normalizeDate($filters['dateTo'] ?? null);

        if ($dateTo !== null) {
            /** Por día completo: un viaje de las 18:00 entra en un dateTo de ese mismo día. */
            $query->whereDate('recolection_date', '<=', $dateTo);
        }

        /**
         * El término se normaliza como una referencia —colapsando espacios y en mayúsculas—;
         * `order` y `container` están siempre en mayúsculas, así que con eso basta para ser
         * insensible a mayúsculas.
         */
        $search = Trip::normalizeReference($filters['search'] ?? '');

        if ($search !== '') {
            /** Agrupado, o el OR se saltaría el ámbito y los filtros anteriores. */
            $query->where(function ($builder) use ($search) {
                $builder->where('order', 'LIKE', '%'.$search.'%')
                    ->orWhere('container', 'LIKE', '%'.$search.'%');
            });
        }

        /** Orden fijo: lo próximo a recoger primero, y el id desempata entre dos del mismo instante. */
        $query->orderByDesc('recolection_date')->orderByDesc('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getTripById(User $user, int $id): Trip
    {
        /**
         * Deliberadamente sin withTrashed(): quien lee no distingue un viaje borrado de uno
         * que nunca existió, y los dos casos salen por el mismo 404. La distinción solo la
         * hace resolveWritableTrip(), para dar un 400 con sentido a quien intenta escribir.
         */
        $trip = Trip::query()->with(self::RELATIONS)->find($id);

        if ($trip === null) {
            throw new NotFoundError('El viaje no existe');
        }

        /** Existe pero puede no ser suyo: fuera de ámbito es 403, no 404. */
        $this->ensureUserCanSeeTrip($user, $trip);

        return $trip;
    }

    #[Override]
    public function getCurrentTrip(User $user): ?Trip
    {
        /**
         * Las seis relaciones del listado y no las ocho del detalle: la respuesta se pinta
         * con TripListResource, así que cargar client, assignedBy y los documentos del
         * piloto sería pagar tres joins que nadie lee.
         *
         * Sin withTrashed() —como el listado— y sin applyScope(): la condición sobre
         * pilot_id **es** el ámbito del piloto, y volver a aplicarlo sería repetirse.
         */
        return Trip::query()
            ->with(self::LIST_RELATIONS)
            ->where('pilot_id', '=', $user->id)
            /** Sobre el status y no sobre start_date: un viaje devuelto a pending no está en curso. */
            ->where('status', '=', TripStatus::InRoute->value)
            /** Nada impide dos viajes en curso a la vez, así que el desempate se escribe aquí. */
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();
    }

    #[Override]
    public function create(User $user, array $data): Trip
    {
        $catalogs = $this->mapCatalogs($data);

        $this->ensureCatalogsAreUsable($catalogs);

        /**
         * Se construye campo a campo en vez de volcar $data: así los tres campos de
         * tripulación se descartan por construcción, aunque el FormRequest cambie o
         * alguien llame al service directamente.
         */
        $trip = Trip::create([
            ...$catalogs,
            /** Se normaliza aquí aunque el FormRequest ya lo haya hecho: el service es llamable directamente. */
            'order' => Trip::normalizeReference($data['order']),
            'container' => Trip::normalizeReference($data['container']),
            /** Los tres de texto libre entran tal como se teclearon: solo el trim del FormRequest. */
            'destination' => $data['destination'],
            'transport' => $data['transport'],
            'observations' => $data['observations'],
            'recolection_date' => $data['recolectionDate'],
            'ship_date' => $data['shipDate'],
            /** La ruta ya resuelta por el frontend: este service nunca llama a Google. */
            'polyline' => $data['polyline'],
            /** Nace pendiente y sin dueño operativo: solo /assignment llena la tripulación. */
            'status' => TripStatus::Pending,
            'pilot_id' => null,
            'vehicle_id' => null,
            'assigned_by' => null,
            /** El autor sale del usuario autenticado, nunca del body. */
            'registered_by' => $user->id,
        ]);

        return $trip->load(self::RELATIONS);
    }

    #[Override]
    public function update(int $id, array $data): Trip
    {
        $trip = $this->resolveWritableTrip($id);

        /**
         * Se traduce clave a clave contra el mapa: lo que el contrato no nombra —pilotId,
         * vehicleId, assignedBy, registeredBy— no tiene columna donde caer y se ignora en
         * silencio con 200, como el vehicle_id de un gasto.
         */
        $payload = [];

        foreach (self::UPDATABLE_FIELDS as $field => $column) {
            if (array_key_exists($field, $data)) {
                $payload[$column] = in_array($field, self::REFERENCE_FIELDS, true)
                    ? Trip::normalizeReference($data[$field])
                    : $data[$field];
            }
        }

        /**
         * Los catálogos se revalidan siempre, con lo que llega fusionado sobre lo
         * almacenado: aunque el cuerpo solo mueva una fecha, un viaje no puede quedarse
         * apuntando a un puerto que se desactivó desde que se dio de alta.
         */
        $catalogColumns = array_values(self::CATALOG_FIELDS);

        $this->ensureCatalogsAreUsable([
            ...$trip->only($catalogColumns),
            ...array_intersect_key($payload, array_flip($catalogColumns)),
        ]);

        /** Un cuerpo vacío es un no-op que igualmente responde 200. */
        if ($payload !== []) {
            $trip->update($payload);
        }

        return $trip->load(self::RELATIONS);
    }

    #[Override]
    public function destroy(int $id): Trip
    {
        $trip = $this->resolveWritableTrip($id);

        /**
         * Borrado lógico: la fila sigue viva —y por eso las guardas de Client y de
         * ShippingLine miran withTrashed()—, pero desaparece de la API para siempre y el
         * segundo intento lo corta resolveWritableTrip() con un 400.
         */
        $trip->delete();

        return $trip;
    }

    #[Override]
    public function assign(User $user, int $id, array $data): Trip
    {
        $carrier = $user->currentCarrier();

        if ($carrier === null) {
            throw new ForbiddenError('Necesitas pertenecer a una empresa transportista para asignar un viaje');
        }

        /**
         * Todo el chequeo va dentro de la transacción y detrás del lock, no antes: si se
         * leyera fuera, dos transportistas verían el mismo viaje libre y los dos pasarían
         * la comprobación antes de que ninguno escribiera. Mismo patrón que FuelPrice al
         * rotar el vigente.
         */
        $trip = DB::transaction(function () use ($user, $id, $data, $carrier) {
            $trip = $this->resolveWritableTrip($id, lock: true);

            $this->ensureCarrierCanAssign($carrier, $trip);

            /**
             * Congelar la tripulación al arrancar: cambiarle el piloto a un viaje ya
             * iniciado dejaría un start_date puesto por alguien que ya no aparece.
             */
            if ($trip->status !== TripStatus::Pending) {
                throw new BadRequestError('Solo se puede asignar un viaje pendiente');
            }

            $this->ensureCrewIsAssignable((int) $data['pilotId'], (int) $data['vehicleId']);

            /** Los tres campos se escriben juntos: no existe un viaje con piloto y sin assigned_by. */
            $trip->update([
                'pilot_id' => (int) $data['pilotId'],
                'vehicle_id' => (int) $data['vehicleId'],
                /** Quién asignó sale del usuario autenticado, nunca del body. */
                'assigned_by' => $user->id,
            ]);

            /**
             * La primera carga se inserta dentro de la misma transacción y detrás del mismo
             * lock (SPEC 27): si el INSERT falla, la asignación entera se deshace y ningún
             * viaje queda asignado con cero cargas. Reasignar AÑADE otra fila en vez de pisar
             * la anterior, así que la reasignación deja el único rastro de esta spec —piloto
             * y vehículo no lo dejan—.
             */
            TripFuel::create([
                'trip_id' => $trip->id,
                'gallons' => $data['fuelGallons'],
                'fuel_type' => $data['fuelType'],
                /** Nace sin confirmar: la confirma su piloto por /api/trip-fuels/{tripFuel}/confirm. */
                'loaded_at' => null,
                'confirmed_by' => null,
                'registered_by' => $user->id,
            ]);

            return $trip;
        });

        return $trip->load(self::RELATIONS);
    }

    #[Override]
    public function start(User $user, int $id): Trip
    {
        $trip = $this->resolveWritableTrip($id);

        $this->ensureUserIsTheAssignedPilot($user, $trip, 'iniciar');

        if ($trip->start_date !== null) {
            throw new BadRequestError('El viaje ya fue iniciado');
        }

        /** La hora la pone el servidor: aceptarla del cuerpo permitiría declarar un arranque que no fue. */
        $trip->update([
            'start_date' => now(),
            'status' => TripStatus::InRoute,
        ]);

        return $trip->load(self::RELATIONS);
    }

    #[Override]
    public function finish(User $user, int $id): Trip
    {
        $trip = $this->resolveWritableTrip($id);

        $this->ensureUserIsTheAssignedPilot($user, $trip, 'finalizar');

        if ($trip->end_date !== null) {
            throw new BadRequestError('El viaje ya fue finalizado');
        }

        /** Un viaje no se cierra antes de empezar, por mucho que el administrador mueva el status a mano. */
        if ($trip->start_date === null) {
            throw new BadRequestError('El viaje no ha sido iniciado');
        }

        $trip->update([
            'end_date' => now(),
            'status' => TripStatus::Finished,
        ]);

        return $trip->load(self::RELATIONS);
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
     *
     * @param  bool  $lock  hold a row lock until the transaction ends; only assign() needs
     *                      it, and only from inside its transaction, where it is what
     *                      keeps two carriers from taking the same free trip at once.
     */
    private function resolveWritableTrip(int $id, bool $lock = false): Trip
    {
        /** Con los borrados a la vista: sin ellos, el segundo DELETE solo podría ser un 404. */
        $trip = Trip::withTrashed()
            ->with(self::RELATIONS)
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())
            ->find($id);

        if ($trip === null) {
            throw new NotFoundError('El viaje no existe');
        }

        if ($trip->trashed()) {
            throw new BadRequestError('El viaje ya fue eliminado');
        }

        return $trip;
    }

    /**
     * Translate the four catalog ids of a payload from the body's names to the columns'.
     *
     * @param  array<string, mixed>  $data
     * @return array{client_id: int, shipping_line_id: int, departure_point_id: int, location_id: int}
     */
    private function mapCatalogs(array $data): array
    {
        $catalogs = [];

        foreach (self::CATALOG_FIELDS as $field => $column) {
            $catalogs[$column] = (int) $data[$field];
        }

        return $catalogs;
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
     * Refuse a company that is not allowed to touch this trip's crew.
     *
     * A trip nobody has taken is free for **any** company —that is what the pool is—;
     * once taken, only the company of its `assigned_by` may touch it again, whichever of
     * its users calls. Freedom is decided on `assigned_by` alone and not on the pool
     * test, so a trip the administrator moved out of `pending` while still unassigned
     * falls through to the clearer 400 below instead of a misleading 403.
     *
     * There is no administrator branch on purpose: assigning is the act by which a
     * company takes a trip, and an administrator doing it would be deciding for the
     * carrier. The route already keeps him out; this guard would too.
     *
     * Throws a ForbiddenError when another company already took the trip.
     */
    private function ensureCarrierCanAssign(Carrier $carrier, Trip $trip): void
    {
        if ($trip->assigned_by === null) {
            return;
        }

        if ($this->resolveAssignerCarrierId($trip) === $carrier->id) {
            return;
        }

        throw new ForbiddenError('No puedes asignar un viaje que ya tomó otra empresa transportista');
    }

    /**
     * Refuse anybody but the trip's own pilot.
     *
     * The two execution marks belong to whoever drives the trip: not to his company, not
     * to another pilot of the same company and not to the administrator, who cannot
     * reach these two routes at all.
     *
     * Throws a ForbiddenError when the caller is not the trip's pilot.
     *
     * @param  string  $action  Spanish infinitive used to build the message.
     */
    private function ensureUserIsTheAssignedPilot(User $user, Trip $trip, string $action): void
    {
        if ($trip->pilot_id !== $user->id) {
            throw new ForbiddenError("No puedes {$action} un viaje que no tienes asignado");
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
     * Narrow a listing query down to what the given user is allowed to see.
     *
     * The SQL counterpart of ensureUserCanSeeTrip(), and the same matrix: an
     * administrator and a manager get no condition at all; a pilot gets his own trips,
     * with the pool deliberately left out —he does not pick trips, they are handed to
     * him—; anybody else gets the pool **or** whatever their own company assigned.
     *
     * The company comparison lands on the users of that company, not on the caller
     * alone: a trip taken by a colleague is visible to every member, and it stays
     * visible after that colleague leaves.
     *
     * @param  Builder<Trip>  $query
     */
    private function applyScope(Builder $query, User $user): void
    {
        if (in_array($user->role, [UserRole::Administrator, UserRole::Manager], true)) {
            return;
        }

        if ($user->role === UserRole::Pilot) {
            $query->where('pilot_id', '=', $user->id);

            return;
        }

        $carrier = $user->currentCarrier();
        $carrierUserIds = $carrier === null ? [] : $this->resolveCarrierUserIds($carrier);

        /** Agrupado: sin el paréntesis el OR se llevaría por delante los filtros del usuario. */
        $query->where(function ($builder) use ($carrierUserIds) {
            $builder->where(function ($pool) {
                $pool->where('status', '=', TripStatus::Pending->value)
                    ->whereNull('pilot_id')
                    ->whereNull('vehicle_id');
            });

            if ($carrierUserIds !== []) {
                $builder->orWhereIn('assigned_by', $carrierUserIds);
            }
        });
    }

    /**
     * List every user id belonging to the given company, owner and pilots alike.
     *
     * Feeds the scope condition: `assigned_by` stores a user, so turning the company
     * into its members is what lets the comparison be about the company.
     *
     * @return list<int>
     */
    private function resolveCarrierUserIds(Carrier $carrier): array
    {
        return [$carrier->user_id, ...$carrier->pilots()->pluck('users.id')->all()];
    }

    /**
     * Read a date filter, returning null for anything that is not exactly a Y-m-d date.
     *
     * Tolerant on purpose, like every other filter of the project: a malformed value is
     * ignored and the listing comes back whole, instead of empty or with a 422.
     */
    private function normalizeDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $value);

        if ($date === false || $date->format(self::DATE_FORMAT) !== $value) {
            return null;
        }

        return $date->format(self::DATE_FORMAT);
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
     * to [1, 100]: the requested size is honoured as it comes and only the ceiling
     * still bites, so nobody asks for the whole table at once.
     */
    private function resolvePerPage(?string $limit): ?int
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }
}
