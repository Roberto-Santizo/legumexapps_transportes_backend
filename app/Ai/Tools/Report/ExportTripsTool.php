<?php

namespace App\Ai\Tools\Report;

use App\Ai\Tools\AssistantTool;
use App\Enums\TripStatus;
use App\Interfaces\Report\ReportServiceInterface;
use App\Models\User;
use App\Services\Report\ReportService;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * `trips` as a spreadsheet: the same filters and scope, but the whole set written to
 * an `.xlsx` the model hands back as a link instead of rows in the context window.
 *
 * The first tool of the assistant that leaves something behind —a file in the
 * bucket—, although it still writes nothing in the database. There is no `limit`:
 * an export wants everything, capped by the service at `ReportService::MAX_ROWS`.
 */
class ExportTripsTool extends AssistantTool
{
    protected const array FILTERS = ['status', 'clientId', 'shippingLineId', 'locationId', 'pilotId', 'vehicleId', 'dateFrom', 'dateTo', 'search'];

    public function __construct(
        User $user,
        private readonly ReportServiceInterface $reports,
    ) {
        parent::__construct($user);
    }

    public function name(): string
    {
        return 'export_trips';
    }

    public function description(): string
    {
        $maxRows = ReportService::MAX_ROWS;

        return "Genera un archivo Excel (.xlsx) con los viajes que cumplen los filtros —las mismas columnas que trips: orden, estado, naviera, punto de partida, puerto, contenedor, fechas, kilómetros y horas estimados, observaciones, piloto, placa y quién lo registró— y devuelve la URL pública para descargarlo. Úsala solo cuando el usuario pida explícitamente un archivo, un Excel, un reporte o exportar; para responder una cifra o listar en pantalla usa trips o trips_summary. Acepta los mismos filtros que trips (sin limit): para «viajes terminados» envía status=finished. Devuelve fileName (nombre para mostrar), url (preséntala como enlace), rows (filas exportadas), total (viajes que cumplen los filtros) y truncated: si es true, el archivo trae solo los primeros {$maxRows} viajes y debes ofrecer acotar el periodo o los filtros. Respeta el mismo ámbito que trips.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(array_column(TripStatus::cases(), 'value'))
                ->description('Estado del viaje. Un valor fuera de la lista se ignora.'),
            'clientId' => $schema->integer()->description('Id del cliente del viaje.'),
            'shippingLineId' => $schema->integer()->description('Id de la naviera del viaje.'),
            'locationId' => $schema->integer()->description('Id del puerto de destino del viaje.'),
            'pilotId' => $schema->integer()->description('Id del piloto asignado al viaje.'),
            'vehicleId' => $schema->integer()->description('Id del vehículo asignado al viaje.'),
            ...$this->dateRangeSchema($schema, 'la fecha de recolección del viaje'),
            'search' => $schema->string()
                ->description('Texto a buscar dentro del número de orden o del contenedor, sin distinguir mayúsculas. Basta con una parte.'),
        ];
    }

    protected function query(array $filters): array
    {
        return $this->reports->exportTrips($this->user, $filters);
    }
}
