<?php

namespace App\Services\TripTimeout;

use App\Enums\UserRole;
use App\Errors\ForbiddenError;
use App\Interfaces\Trip\TripServiceInterface;
use App\Interfaces\TripTimeout\TripTimeoutServiceInterface;
use App\Models\Trip;
use App\Models\TripPosition;
use App\Models\TripTimeout;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Override;

class TripTimeoutService implements TripTimeoutServiceInterface
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
     * so this service never rewrites that matrix, exactly like TripPositionService.
     *
     * The dependency only runs in this direction: TripService closes the open stop by
     * touching the TripTimeout model directly instead of injecting this contract,
     * because the contract in reverse would close a cycle in the container.
     */
    public function __construct(private TripServiceInterface $tripService) {}

    #[Override]
    public function getTimeouts(User $user, int $tripId, array $filters): LengthAwarePaginator|Collection
    {
        $trip = $this->resolveWatchedTrip($user, $tripId);

        /**
         * Del principio al final del viaje, como el rastro de SPEC 26 y al revés que el
         * resto de listados del proyecto. El desempate por id fija el orden de dos paradas
         * que arrancasen en el mismo segundo.
         */
        $query = TripTimeout::query()
            ->where('trip_id', $trip->id)
            ->orderBy('started_at')
            ->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null
            ? $query->get()
            : $query->paginate($perPage);
    }

    #[Override]
    public function trackPosition(TripPosition $position): void
    {
        $openTimeout = $this->openTimeoutFor($position->trip_id);

        if ($openTimeout !== null) {
            $this->closeIfMoved($openTimeout, $position);

            return;
        }

        $this->openIfStill($position);
    }

    /**
     * Resolve the trip somebody is watching the stops of.
     *
     * One rule of its own —a pilot never reads the stops, not even those of his own
     * trip: he reports and nothing else, exactly as with the track and the channel of
     * SPEC 26— and the rest delegated on getTripById(), which already throws 404
     * outside the reach of the caller and 403 outside its company.
     */
    private function resolveWatchedTrip(User $user, int $tripId): Trip
    {
        if ($user->role === UserRole::Pilot) {
            throw new ForbiddenError('No tienes permisos para consultar las paradas de un viaje');
        }

        return $this->tripService->getTripById($user, $tripId);
    }

    /**
     * Close the open stop when the new point proves the truck moved away from the anchor.
     *
     * The distance is measured against the **anchor**, never against the previous point:
     * a truck creeping four metres every fifteen seconds in slow traffic would otherwise
     * stay "stopped" forever, four hundred metres down the road.
     *
     * Under the threshold **not a single column is touched**, `updated_at` included: the
     * row keeps saying when the stop started and nothing else changes.
     */
    private function closeIfMoved(TripTimeout $timeout, TripPosition $position): void
    {
        $meters = DistanceCalculator::metersBetween(
            (float) $timeout->latitude,
            (float) $timeout->longitude,
            (float) $position->latitude,
            (float) $position->longitude,
        );

        if ($meters < DistanceCalculator::MOVEMENT_THRESHOLD_METERS) {
            return;
        }

        $timeout->update([
            'ended_at' => $position->recorded_at,
            'end_position_id' => $position->id,
        ]);
    }

    /**
     * Open a stop when the new point barely moved from the trip's previous one.
     *
     * The anchor is the **previous point**, not the one that detected the stop: «he has
     * been stopped since 08:14» is the useful fact, and anchoring it on the detection
     * would silently drop the first stretch of the rest.
     *
     * Its coordinates are copied into the row so a map can draw the pin without a second
     * trip to the database; the copy cannot drift because a position is immutable.
     */
    private function openIfStill(TripPosition $position): void
    {
        $previousPosition = $this->positionBefore($position);

        /** Primer punto del viaje: no hay contra qué medir, así que no hay nada que decidir. */
        if ($previousPosition === null) {
            return;
        }

        $meters = DistanceCalculator::metersBetween(
            (float) $previousPosition->latitude,
            (float) $previousPosition->longitude,
            (float) $position->latitude,
            (float) $position->longitude,
        );

        if ($meters >= DistanceCalculator::MOVEMENT_THRESHOLD_METERS) {
            return;
        }

        TripTimeout::create([
            'trip_id' => $position->trip_id,
            /** El autor es el mismo del punto: aquí no hay registered_by. */
            'pilot_id' => $previousPosition->pilot_id,
            'start_position_id' => $previousPosition->id,
            'end_position_id' => null,
            'latitude' => $previousPosition->latitude,
            'longitude' => $previousPosition->longitude,
            'started_at' => $previousPosition->recorded_at,
            'ended_at' => null,
        ]);
    }

    /**
     * Get the trip's open stop, or null when the truck is moving.
     *
     * The invariant «at most one open stop per trip» lives in the code, not in a partial
     * unique index: this method and the trip's finish are the only two things that ever
     * close a row, and only openIfStill() opens one. Ordering by started_at desc means
     * that if two open rows ever coexisted, the most recent one would be closed and the
     * older one would stay visible as an odd row, never as a 500.
     */
    private function openTimeoutFor(int $tripId): ?TripTimeout
    {
        return TripTimeout::query()
            ->where('trip_id', $tripId)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Get the point recorded right before the given one, or null when it is the first.
     *
     * «Before» is the listing order read backwards —recorded_at, then id— and not simply
     * «the latest one», so the comparison keeps meaning the same thing if this is ever
     * called with a point that is not the newest of its trip.
     */
    private function positionBefore(TripPosition $position): ?TripPosition
    {
        return TripPosition::query()
            ->where('trip_id', $position->trip_id)
            ->where(function (Builder $query) use ($position): void {
                $query->where('recorded_at', '<', $position->recorded_at)
                    ->orWhere(function (Builder $tie) use ($position): void {
                        $tie->where('recorded_at', $position->recorded_at)
                            ->where('id', '<', $position->id);
                    });
            })
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();
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
