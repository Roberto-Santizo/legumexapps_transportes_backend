<?php

namespace App\Ai\Tools\Report;

use App\Ai\Tools\AssistantTool;
use App\Interfaces\Report\ReportServiceInterface;
use App\Models\User;
use App\Services\Report\ReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;

/**
 * `trip_emergency_expenses` as a spreadsheet (SPEC 39): the emergency expenses of one
 * trip written to an `.xlsx`, with the receipt as a URL column where there is one.
 *
 * Same required `tripId` as the listing tool, no filters and no `limit`: the cap is
 * `ReportService::MAX_ROWS`.
 */
class ExportTripEmergencyExpensesTool extends AssistantTool
{
    public function __construct(
        User $user,
        private readonly ReportServiceInterface $reports,
    ) {
        parent::__construct($user);
    }

    public function name(): string
    {
        return 'export_trip_emergency_expenses';
    }

    public function description(): string
    {
        $maxRows = ReportService::MAX_ROWS;

        return "Genera un archivo Excel (.xlsx) con los gastos emergentes de un viaje —imprevistos pagados en carretera, NO viáticos— con monto en quetzales, descripción, enlace al comprobante, quién lo registró y sus fechas de registro y última corrección, y devuelve la URL pública para descargarlo. Úsala solo cuando el usuario pida explícitamente un archivo, un Excel, un reporte o exportar; para responder una cifra usa trip_emergency_expenses. Requiere el id del viaje: si solo tienes la orden o el contenedor, resuélvelo antes con trips. Devuelve fileName (nombre para mostrar), url (preséntala como enlace), rows (filas exportadas), total (gastos emergentes del viaje), totalAmount (suma de todos, no solo los exportados) y truncated: si es true, el archivo trae solo los primeros {$maxRows}.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tripId' => $schema->integer()->required()->description('Id del viaje. Si no lo conoces, búscalo antes con trips.'),
        ];
    }

    protected function arguments(Request $request): array
    {
        return ['tripId' => $this->resourceId($request, 'tripId')];
    }

    protected function query(array $filters): array
    {
        return $this->reports->exportTripEmergencyExpenses($this->user, $filters);
    }
}
