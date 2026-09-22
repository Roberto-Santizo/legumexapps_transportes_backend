<?php

namespace App\Ai\Tools\Trip;

use App\Http\Resources\TripCost\TripCostResource;
use App\Interfaces\TripCost\TripCostServiceInterface;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * `GET /api/trips/{trip}/cost` as a tool: what one finished trip cost, broken down.
 *
 * The only nested tool that does not paginate —the cost is a single object, not a
 * listing—, so it takes the required `tripId` of `TripNestedTool` and nothing else.
 * A trip that has not finished comes back as an `error` with the same message the
 * endpoint returns, for the model to explain instead of guessing a partial cost.
 */
class TripCostTool extends TripNestedTool
{
    public function __construct(
        User $user,
        private readonly TripCostServiceInterface $costs,
    ) {
        parent::__construct($user);
    }

    public function name(): string
    {
        return 'trip_cost';
    }

    public function description(): string
    {
        return 'El costo directo en quetzales de un viaje FINALIZADO, desglosado en cuatro componentes: combustible confirmado cotizado al precio vigente el día de cada carga, viáticos confirmados, salario del piloto y seguro del vehículo prorrateados por las horas reales del viaje sobre un mes de 720 horas. Devuelve además el total. Solo funciona con viajes finalizados: uno pendiente o en ruta devuelve error. No incluye depreciación, mantenimiento, peajes ni ingresos, así que no es la rentabilidad del viaje. Si un insumo sale en null (monthlySalary, monthlyInsuranceCost, traveledHours o pricePerGallon) ese componente vale 0 y falta el dato, no es que fuera gratis. Si no conoces el id, búscalo antes con trips.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tripId' => $this->tripIdSchema($schema),
        ];
    }

    /**
     * Only the trip id: there is no page to ask for, so the `limit` of the nested
     * listings has nothing to default to here.
     */
    protected function arguments(Request $request): array
    {
        return ['tripId' => $this->resourceId($request, 'tripId')];
    }

    protected function query(array $filters): array
    {
        $cost = $this->costs->getTripCost($this->user, $filters['tripId']);

        return new TripCostResource($cost)->resolve();
    }
}
