<?php

namespace App\Services\TripFuel;

use App\Enums\TripStatus;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Trip\TripServiceInterface;
use App\Interfaces\TripFuel\TripFuelServiceInterface;
use App\Models\Trip;
use App\Models\TripFuel;
use App\Models\User;
use Override;

class TripFuelService implements TripFuelServiceInterface
{
    /**
     * Smallest page size accepted, as in the rest of the project.
     */
    private const MIN_PER_PAGE = 10;

    /**
     * Largest page size accepted, so nobody asks for the whole table at once.
     */
    private const MAX_PER_PAGE = 100;

    /**
     * The trip domain resolves both the trip and the reading scope of SPEC 24.
     *
     * Injected by constructor —the by method parameter rule is the controller's alone—
     * so this service never rewrites that matrix. Precedent: the TripPositionService of
     * SPEC 26 and, before it, the FreightRateService that injected ZoneServiceInterface
     * until SPEC 15.
     */
    public function __construct(private TripServiceInterface $tripService) {}

    #[Override]
    public function getTripFuels(User $user, int $tripId, array $filters): array
    {
        /**
         * El ámbito de SPEC 24 no se reescribe: getTripById() ya lanza 404 fuera del
         * alcance de quien pregunta y 403 fuera de su empresa. Y aquí, a diferencia del
         * rastro de SPEC 26, NO hay una regla extra contra el piloto: el asignado lee las
         * cargas de su propio viaje, porque el dato es sobre él.
         */
        $trip = $this->tripService->getTripById($user, $tripId);

        $query = TripFuel::query()
            ->with(['confirmedBy', 'registeredBy'])
            ->where('trip_id', $trip->id);

        /**
         * El acumulado se calcula sobre la consulta clonada y ANTES de paginar, así que
         * ?limit=10 sobre un viaje de 25 cargas sigue devolviendo el total del viaje.
         * Solo cuentan las confirmadas: el número que importa es cuánto combustible llegó
         * de verdad al camión, aunque eso deje un viaje recién asignado en "0.00".
         */
        $totalGallons = (clone $query)->whereNotNull('loaded_at')->sum('gallons');

        /**
         * Orden fijo `id ASC`: `loaded_at` es nullable y no sirve para ordenar, y
         * `created_at` empataría entre dos cargas del mismo segundo. El id es la
         * cronología real de registro.
         */
        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return [
            'fuels' => $perPage === null ? $query->get() : $query->paginate($perPage),
            'totalGallons' => number_format((float) $totalGallons, 2, '.', ''),
        ];
    }

    #[Override]
    public function create(User $user, int $tripId, array $data): TripFuel
    {
        $trip = $this->resolveFuelableTrip($user, $tripId);

        /**
         * Nace sin confirmar: registrar no es confirmar. `loaded_at` y `confirmed_by`
         * quedan en null hasta que el piloto asignado pase por /confirm.
         */
        return TripFuel::create([
            'trip_id' => $trip->id,
            'gallons' => $data['gallons'],
            'fuel_type' => $data['fuelType'],
            'loaded_at' => null,
            'confirmed_by' => null,
            /** El autor sale del usuario autenticado, nunca del body. */
            'registered_by' => $user->id,
        ]);
    }

    #[Override]
    public function confirm(User $user, int $tripFuelId): TripFuel
    {
        //
    }

    /**
     * Resolve the trip a carrier company is registering a fuel load on.
     *
     * The four guards of the POST, in the order the contract fixes —the literal
     * precedent of SPEC 26—: the trip must exist, must not be deleted, must have been
     * taken by the caller's company and must not be finished. Hence a trip that is both
     * deleted and someone else's answers 400 and not 403.
     *
     * Reads withTrashed() on purpose —like resolveWritableTrip() in the trip domain— so
     * a deleted trip answers 400 «El viaje ya fue eliminado» and stays distinguishable
     * from an id that never existed.
     */
    private function resolveFuelableTrip(User $user, int $tripId): Trip
    {
        $trip = Trip::withTrashed()->find($tripId);

        if ($trip === null) {
            throw new NotFoundError('El viaje no existe');
        }

        if ($trip->trashed()) {
            throw new BadRequestError('El viaje ya fue eliminado');
        }

        $this->ensureCarrierTookTheTrip($user, $trip);

        /**
         * Se carga combustible en `pending` Y en `in_route` —una recarga en carretera es
         * el caso real—, nunca después: un viaje cerrado ya no recibe nada.
         */
        if ($trip->status === TripStatus::Finished) {
            throw new BadRequestError('El viaje ya fue finalizado');
        }

        return $trip;
    }

    /**
     * Refuse a company that did not take this trip.
     *
     * Unlike the pool of SPEC 24, an unassigned trip is **not** free here: a load with
     * no pilot to confirm it would be born stuck, so «nobody took it» and «another
     * company took it» share the same 403.
     *
     * The comparison lands on the **company** of `assigned_by` and not on the user
     * itself, exactly as every scope check of SPEC 24 does: any user of the company
     * that took the trip may register a load, not only the person who assigned it.
     */
    private function ensureCarrierTookTheTrip(User $user, Trip $trip): void
    {
        $carrier = $user->currentCarrier();

        if ($carrier === null) {
            throw new ForbiddenError('No perteneces a ninguna empresa transportista');
        }

        if ($trip->assignedBy?->currentCarrier()?->id !== $carrier->id) {
            throw new ForbiddenError('No puedes registrar combustible en un viaje que no tomó tu empresa transportista');
        }
    }

    /**
     * Resolve the page size requested by the client.
     *
     * A missing or non numeric limit means "do not paginate"; a numeric one is clamped
     * to [10, 100]. Opt in like the rest of the project.
     */
    private function resolvePerPage(?string $limit): ?int
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }
}
