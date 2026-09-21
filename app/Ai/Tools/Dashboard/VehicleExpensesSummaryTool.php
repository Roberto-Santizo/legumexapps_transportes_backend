<?php

namespace App\Ai\Tools\Dashboard;

use App\Http\Resources\Dashboard\VehicleExpensesSummaryResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * `GET /api/dashboard/vehicle-expenses` as a tool: the maintenance expense aggregates.
 */
class VehicleExpensesSummaryTool extends DashboardTool
{
    protected const array FILTERS = ['carrierId', 'dateFrom', 'dateTo'];

    public function name(): string
    {
        return 'vehicle_expenses_summary';
    }

    public function description(): string
    {
        return 'Agregados de gastos de mantenimiento de vehículos: monto total y conteo, desglose por categoría, por naturaleza (preventive/corrective), facturado vs no facturado, por empresa transportista y por mes (YYYY-MM, solo meses con datos). Los importes son quetzales como texto con dos decimales. La empresa es la dueña del vehículo, esté activo o no. El rango de fechas corta sobre la fecha del gasto; sin fechas agrega todo el histórico.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'carrierId' => $this->carrierIdSchema($schema),
            ...$this->dateRangeSchema($schema, 'la fecha del gasto'),
        ];
    }

    protected function query(array $filters): array
    {
        return new VehicleExpensesSummaryResource($this->dashboard->getVehicleExpensesSummary($this->user, $filters))->resolve();
    }
}
