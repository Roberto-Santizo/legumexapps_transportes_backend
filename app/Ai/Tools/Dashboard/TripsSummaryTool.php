<?php

namespace App\Ai\Tools\Dashboard;

use App\Http\Resources\Dashboard\TripsSummaryResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * `GET /api/dashboard/trips` as a tool: the trip aggregates.
 */
class TripsSummaryTool extends DashboardTool
{
    protected const array FILTERS = ['carrierId', 'dateFrom', 'dateTo'];

    public function name(): string
    {
        return 'trips_summary';
    }

    public function description(): string
    {
        return 'Agregados de viajes: total, viajes sin asignar (pendientes sin piloto ni vehículo), desglose por estado (pending, inRoute, finished), por empresa transportista, por cliente, por naviera, por puerto de destino y por mes (YYYY-MM, solo meses con datos). El rango de fechas corta sobre la fecha de recolección; sin fechas agrega todo el histórico. Los viajes eliminados nunca cuentan. Los viajes sin asignar no aparecen en byCarrier, así que su suma puede ser menor que total.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'carrierId' => $this->carrierIdSchema($schema),
            ...$this->dateRangeSchema($schema, 'la fecha de recolección del viaje'),
        ];
    }

    protected function query(array $filters): array
    {
        return new TripsSummaryResource($this->dashboard->getTripsSummary($this->user, $filters))->resolve();
    }
}
