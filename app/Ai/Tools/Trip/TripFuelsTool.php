<?php

namespace App\Ai\Tools\Trip;

use App\Http\Resources\TripFuel\TripFuelResource;
use App\Interfaces\TripFuel\TripFuelServiceInterface;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * `GET /api/trips/{trip}/fuels` as a tool: the fuel loads of one trip.
 */
class TripFuelsTool extends TripNestedTool
{
    public function __construct(
        User $user,
        private readonly TripFuelServiceInterface $fuels,
    ) {
        parent::__construct($user);
    }

    public function name(): string
    {
        return 'trip_fuels';
    }

    public function description(): string
    {
        return 'Las cargas de combustible registradas en un viaje, de la más antigua a la más reciente. Por carga: galones, tipo de combustible (regular, premium, diesel, diesel_premium), si el piloto la confirmó (isConfirmed), cuándo (loadedAt, null si no), quién la confirmó y quién la registró. totalGallons suma solo las confirmadas, que es el mismo número que totalFuelGallons en el detalle del viaje. Un viaje sin cargas devuelve una lista vacía.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tripId' => $this->tripIdSchema($schema),
            'limit' => $this->limitSchema($schema, 10, self::DEFAULT_LIMIT, 'cargas'),
        ];
    }

    protected function query(array $filters): array
    {
        $result = $this->fuels->getTripFuels($this->user, $filters['tripId'], ['limit' => $filters['limit']]);

        /** @var LengthAwarePaginator $fuels */
        $fuels = $result['fuels'];

        return [
            'totalGallons' => $result['totalGallons'],
            ...$this->pageOf($fuels),
            'fuels' => TripFuelResource::collection($fuels->getCollection())->resolve(),
        ];
    }
}
