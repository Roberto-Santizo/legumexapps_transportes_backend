<?php

namespace App\Services\TripPosition;

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Events\Trip\TripPositionUpdated;
use App\Interfaces\Trip\TripServiceInterface;
use App\Interfaces\TripPosition\TripPositionServiceInterface;
use App\Interfaces\TripTimeout\TripTimeoutServiceInterface;
use App\Models\Trip;
use App\Models\TripPosition;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Override;
use Throwable;

class TripPositionService implements TripPositionServiceInterface
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
     * Seconds that must pass between two recorded points of the same trip.
     *
     * The only brake of the domain: there is no rate limit per IP nor per token. Below
     * it the request is answered with the previous point and nothing is written — a
     * five second cadence on a six hour trip is ~4 300 rows without any useful gain in
     * precision.
     */
    private const MIN_SECONDS_BETWEEN_POSITIONS = 15;

    /**
     * The trip domain resolves both the trip and the reading scope of SPEC 24, and the
     * timeout domain turns each recorded point into the trip's stops (SPEC 27).
     *
     * Both injected by constructor —the by method parameter rule is the controller's
     * alone— so this service never rewrites the scope matrix nor the detection. First
     * precedent: the FreightRateService that injected ZoneServiceInterface until SPEC 15.
     */
    public function __construct(
        private TripServiceInterface $tripService,
        private TripTimeoutServiceInterface $tripTimeoutService,
    ) {}

    #[Override]
    public function getPositions(User $user, int $tripId, array $filters): LengthAwarePaginator|Collection
    {
        $trip = $this->resolveTrackedTrip($user, $tripId);

        /**
         * Orden de llegada y desempate por id: dos puntos del mismo segundo —posible con
         * un piso medido en segundos— tienen que salir siempre en el mismo orden.
         */
        $query = TripPosition::query()
            ->where('trip_id', $trip->id)
            ->orderBy('recorded_at')
            ->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null
            ? $query->get()
            : $query->paginate($perPage);
    }

    #[Override]
    public function create(User $user, int $tripId, array $data): TripPosition
    {
        $trip = $this->resolveReportableTrip($user, $tripId);

        $lastPosition = $this->lastPositionFor($trip->id);

        /**
         * Piso de 15 segundos: se devuelve el punto anterior tal cual, sin escribir y sin
         * emitir. Silencio deliberado, con el precedente del archivo ignorado de SPEC 19:
         * la app del piloto reintenta cuando la red va mal, y devolverle un error por
         * reintentar la empujaría a lógica defensiva propia.
         */
        if ($lastPosition !== null && $this->secondsSince($lastPosition) < self::MIN_SECONDS_BETWEEN_POSITIONS) {
            return $lastPosition;
        }

        $position = TripPosition::create([
            'trip_id' => $trip->id,
            /** El autor sale del usuario autenticado, nunca del body. */
            'pilot_id' => $user->id,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            /** Ídem la hora: aceptarla del dispositivo la haría falsificable y desordenaría el rastro. */
            'recorded_at' => now(),
        ]);

        /**
         * Solo aquí, nunca en la rama del piso de 15 s: evaluar una petición descartada
         * abriría paradas a partir de puntos que no llegaron al rastro.
         *
         * Va fuera del try/catch del broadcast a propósito: perder el aviso en vivo no
         * puede costar el punto, pero una parada mal detectada sí es un dato equivocado,
         * así que si la detección falla, falla la petición.
         */
        $this->tripTimeoutService->trackPosition($position);

        $this->broadcastPosition($position, $user);

        return $position;
    }

    /**
     * Resolve the trip a pilot is reporting a position for.
     *
     * The four guards of the POST, in the order the contract fixes: the trip must
     * exist, must not be deleted, must belong to the caller and must be in route.
     *
     * Reads withTrashed() on purpose —like resolveWritableTrip() in the trip domain— so
     * a deleted trip answers 400 «El viaje ya fue eliminado» and stays distinguishable
     * from an id that never existed.
     */
    private function resolveReportableTrip(User $user, int $tripId): Trip
    {
        $trip = Trip::withTrashed()->find($tripId);

        if ($trip === null) {
            throw new NotFoundError('El viaje no existe');
        }

        if ($trip->trashed()) {
            throw new BadRequestError('El viaje ya fue eliminado');
        }

        if ($trip->pilot_id !== $user->id) {
            throw new ForbiddenError('No puedes reportar la posición de un viaje que no tienes asignado');
        }

        /** Fuera de ruta no hay dónde poner un punto: ni antes de arrancar, ni después de cerrar. */
        if ($trip->status !== TripStatus::InRoute) {
            throw new BadRequestError('El viaje no está en ruta');
        }

        return $trip;
    }

    /**
     * Resolve the trip somebody is watching the track of.
     *
     * One rule of its own —a pilot never reads a track, not even his own: he reports
     * and nothing else— and the rest delegated on getTripById(), which already throws
     * 404 outside the reach of the caller and 403 outside its company. The same path
     * the channel callback of routes/channels.php walks, so the websocket and the
     * endpoint cannot start saying different things.
     */
    private function resolveTrackedTrip(User $user, int $tripId): Trip
    {
        if ($user->role === UserRole::Pilot) {
            throw new ForbiddenError('No tienes permisos para consultar el rastro de un viaje');
        }

        return $this->tripService->getTripById($user, $tripId);
    }

    /**
     * Get the last point recorded for the given trip, or null when there is none yet.
     *
     * Ordered by recorded_at desc, id desc —the same tie break as the listing, read
     * backwards— and served by the composite index of the table. Its only consumer is
     * the 15 second floor.
     */
    private function lastPositionFor(int $tripId): ?TripPosition
    {
        return TripPosition::query()
            ->where('trip_id', $tripId)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Seconds elapsed since the given point was recorded.
     *
     * Absolute on purpose: a clock that drifts backwards must not turn into a negative
     * age that slips past the floor.
     */
    private function secondsSince(TripPosition $position): int
    {
        return (int) abs($position->recorded_at?->diffInSeconds(now()) ?? PHP_INT_MAX);
    }

    /**
     * Push the recorded point to whoever is watching the trip's map.
     *
     * Wrapped in a try/catch that logs and carries on: Reverb being down —no
     * reverb:start running, a dead process, a closed port— must not tumble the pilot's
     * POST nor leave the row unwritten. The live notice is lost, the data is not, and
     * the log is the only signal that it happened.
     */
    private function broadcastPosition(TripPosition $position, User $user): void
    {
        try {
            /** El nombre viaja por constructor: el evento no vuelve a la base por él. */
            event(new TripPositionUpdated($position, $user->name));
        } catch (Throwable $th) {
            Log::error('No se pudo emitir la posición del viaje', [
                'tripId' => $position->trip_id,
                'positionId' => $position->id,
                'exception' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the page size requested by the client.
     *
     * A missing or non numeric limit means "do not paginate"; a numeric one is clamped
     * to [10, 100]. Opt in like the rest of the project, and the first listing where
     * "the whole thing" may well mean thousands of elements.
     */
    private function resolvePerPage(?string $limit): ?int
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }
}
