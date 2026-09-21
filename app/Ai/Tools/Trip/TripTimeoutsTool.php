<?php

namespace App\Ai\Tools\Trip;

use App\Http\Resources\TripTimeout\TripTimeoutResource;
use App\Interfaces\TripTimeout\TripTimeoutServiceInterface;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * `GET /api/trips/{trip}/timeouts` as a tool: the stops detected on one trip.
 */
class TripTimeoutsTool extends TripNestedTool
{
    public function __construct(
        User $user,
        private readonly TripTimeoutServiceInterface $timeouts,
    ) {
        parent::__construct($user);
    }

    public function name(): string
    {
        return 'trip_timeouts';
    }

    public function description(): string
    {
        return 'Las paradas detectadas en un viaje a partir del rastro GPS, de la más antigua a la más reciente. Por parada: coordenadas del punto donde se detuvo, inicio (startedAt), fin (endedAt, null si sigue detenido) y duración en minutos (durationMinutes, null mientras esté abierta). Una parada cerrada con endPositionId null terminó porque el viaje se finalizó, no porque el camión se moviera. Incluye paradas breves como un semáforo: no hay umbral mínimo. Un viaje sin paradas devuelve una lista vacía.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tripId' => $this->tripIdSchema($schema),
            'limit' => $this->limitSchema($schema, 10, self::DEFAULT_LIMIT, 'paradas'),
        ];
    }

    protected function query(array $filters): array
    {
        /** @var LengthAwarePaginator $timeouts */
        $timeouts = $this->timeouts->getTimeouts($this->user, $filters['tripId'], ['limit' => $filters['limit']]);

        return [
            ...$this->pageOf($timeouts),
            'timeouts' => TripTimeoutResource::collection($timeouts->getCollection())->resolve(),
        ];
    }
}
