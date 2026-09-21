<?php

namespace App\Ai\Tools\Report;

use App\Ai\Tools\AssistantTool;
use App\Enums\VehicleExpenseCategory;
use App\Enums\VehicleExpenseNature;
use App\Interfaces\Report\ReportServiceInterface;
use App\Models\User;
use App\Services\Report\ReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * `vehicle_expenses` as a spreadsheet: the maintenance expenses of one vehicle
 * written to an `.xlsx`, with the invoice as a URL column where there is one.
 *
 * Same required `vehicleId` as the listing tool, same tolerant filters, no `limit`:
 * the cap is `ReportService::MAX_ROWS`.
 */
class ExportVehicleExpensesTool extends AssistantTool
{
    protected const array FILTERS = ['category', 'nature', 'dateFrom', 'dateTo', 'isInvoiced'];

    public function __construct(
        User $user,
        private readonly ReportServiceInterface $reports,
    ) {
        parent::__construct($user);
    }

    public function name(): string
    {
        return 'export_vehicle_expenses';
    }

    public function description(): string
    {
        $maxRows = ReportService::MAX_ROWS;

        return "Genera un archivo Excel (.xlsx) con los gastos de mantenimiento de un vehículo —categoría, naturaleza, monto en quetzales, fecha, descripción, si tiene factura, el enlace a la factura y quién lo registró— y devuelve la URL pública para descargarlo. Úsala solo cuando el usuario pida explícitamente un archivo, un Excel, un reporte o exportar; para responder una cifra usa vehicle_expenses. Requiere el id del vehículo: si solo tienes la placa, resuélvelo antes con vehicle. Acepta los mismos filtros que vehicle_expenses (sin limit). Devuelve fileName (nombre para mostrar), url (preséntala como enlace), rows (filas exportadas), total (gastos que cumplen los filtros), totalAmount (suma de todos los que cumplen los filtros, no solo los exportados) y truncated: si es true, el archivo trae solo los primeros {$maxRows} gastos y debes ofrecer acotar el periodo o los filtros.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicleId' => $schema->integer()->required()->description('Id del vehículo. Si solo conoces la placa, obtén el id con vehicle.'),
            'category' => $schema->string()
                ->enum(array_column(VehicleExpenseCategory::cases(), 'value'))
                ->description('Categoría del gasto. Un valor fuera de la lista se ignora.'),
            'nature' => $schema->string()
                ->enum(array_column(VehicleExpenseNature::cases(), 'value'))
                ->description('Naturaleza del gasto: preventive o corrective. Un valor fuera de la lista se ignora.'),
            ...$this->dateRangeSchema($schema, 'la fecha del gasto'),
            'isInvoiced' => $schema->boolean()
                ->description('true: solo gastos con factura; false: solo los que no la tienen. Sin él, todos.'),
        ];
    }

    protected function arguments(Request $request): array
    {
        $arguments = $this->filters($request);
        $arguments['vehicleId'] = $this->resourceId($request, 'vehicleId');

        return $arguments;
    }

    protected function query(array $filters): array
    {
        return $this->reports->exportVehicleExpenses($this->user, $filters);
    }
}
