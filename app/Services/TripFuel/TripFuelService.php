<?php

namespace App\Services\TripFuel;

use App\Interfaces\Trip\TripServiceInterface;
use App\Interfaces\TripFuel\TripFuelServiceInterface;
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
        //
    }

    #[Override]
    public function confirm(User $user, int $tripFuelId): TripFuel
    {
        //
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
