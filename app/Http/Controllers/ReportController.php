<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Report\ExportTripsReportRequest;
use App\Interfaces\Report\ReportServiceInterface;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Reports',
    description: <<<'TEXT'
    Reportes descargables. Un solo endpoint, GET /api/reports/trips, que devuelve EN BINARIO un .xlsx con los viajes cuya recolection_date cae en el rango. ES LA ÚNICA RESPUESTA DE LA API QUE NO ES EL SOBRE JSON: en éxito el cuerpo es el archivo; los errores 400, 401 y 403 salen por el sobre { statusCode, message, data } y el 422 con el formato de validación de Laravel.

    PERMISOS — todo rol salvo pilot (403). Sin carrier.required. EL ÁMBITO ES EL DE GET /api/trips: el carrier recibe la bolsa libre más lo que tomó su empresa; administrator, manager, export, user y shipment, todos los viajes.

    COLUMNAS POR ROL — 22 columnas base para todos; administrator, manager, export y shipment reciben además «Productos» y «Total de cajas» al final (24). carrier y user NO las reciben aunque GET /api/trip-finished-products sí les responda: el reporte tiene su propia matriz.

    Nada se guarda en el bucket: el archivo se genera en la petición y no hay URL ni historial.
    TEXT,
)]
class ReportController extends Controller
{
    /**
     * MIME type of every spreadsheet this controller sends.
     */
    private const string XLSX_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * Download the trips of a date range as an `.xlsx` attachment.
     *
     * The only response of the API that is neither the JSON envelope nor a stream: on
     * success the body is the spreadsheet itself. Errors still go through `ResponseHandler`.
     */
    #[OA\Get(
        path: '/api/reports/trips',
        operationId: 'downloadTripsReport',
        summary: 'Descargar el reporte de viajes (.xlsx)',
        description: <<<'TEXT'
        Devuelve un .xlsx con los viajes cuya recolection_date cae entre dateFrom y dateTo, ambos INCLUSIVOS y por día completo. Mismo ámbito, filtros y orden que GET /api/trips (recolection_date desc, id desc); los viajes borrados nunca salen. limit se ignora: el archivo trae TODOS los viajes del rango.

        CABECERAS: Content-Type application/vnd.openxmlformats-officedocument.spreadsheetml.sheet y Content-Disposition attachment; filename="viajes-{dateFrom}_{dateTo}.xlsx". El frontend hace blob() y descarga con ese nombre.

        COLUMNAS BASE (22, en este orden): Id, Orden, Estado, Cliente, Naviera, Punto de partida, Puerto, Destino final, Transporte, Contenedor, Fecha recolección, Fecha embarque, Inicio, Fin, Km estimados, Horas estimadas, Km reales, Horas reales, Observaciones, Piloto, Placa, Registrado por. Estado en español (Pendiente / En ruta / Finalizado); fechas en d-m-Y h:i:s A (NO ISO 8601); km y horas como celdas NUMÉRICAS; cualquier null es una celda vacía (un viaje de la bolsa trae vacíos Piloto y Placa).

        SOLO PARA administrator, manager, export Y shipment se añaden al final «Productos» —texto «CODE × N cajas; CODE × N cajas» en el orden de las líneas, vacío sin líneas; un SKU borrado sigue saliendo— y «Total de cajas» —número, 0 sin líneas—.

        Un rango sin viajes responde 200 con un archivo que solo trae la fila de encabezados. Con MÁS DE 5000 viajes responde 400 y no genera archivo: acota el rango.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'dateFrom', description: 'Primer día del rango sobre recolection_date, inclusive. OBLIGATORIO, Y-m-d estricto.', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-01')),
            new OA\Parameter(name: 'dateTo', description: 'Último día del rango, inclusive y por día completo. OBLIGATORIO, Y-m-d estricto y no anterior a dateFrom. Sin rango máximo.', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-30')),
            new OA\Parameter(name: 'status', description: 'Opcional y TOLERANTE: pending, in_route o finished; cualquier otro valor se ignora.', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'in_route', 'finished'])),
            new OA\Parameter(name: 'clientId', description: 'Opcional y tolerante: no numérico se ignora.', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'shippingLineId', description: 'Opcional y tolerante: no numérico se ignora.', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'locationId', description: 'Opcional y tolerante: no numérico se ignora.', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'pilotId', description: 'Opcional y tolerante: no numérico se ignora.', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'vehicleId', description: 'Opcional y tolerante: no numérico se ignora.', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', description: 'Opcional: LIKE sobre order y container, insensible a mayúsculas.', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'El .xlsx en binario, con Content-Disposition attachment; filename="viajes-{dateFrom}_{dateTo}.xlsx". Sin sobre JSON.',
                content: new OA\MediaType(
                    mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    schema: new OA\Schema(type: 'string', format: 'binary'),
                ),
            ),
            new OA\Response(response: 400, description: 'El rango trae más de 5000 viajes. Mensaje: El reporte excede 5000 viajes; acota el rango de fechas', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 401, description: 'Token ausente, manipulado o expirado. Mensaje: El token de sesión no es válido o ha expirado', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'El rol es pilot. Mensaje: No tienes permisos para acceder a este recurso', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'dateFrom o dateTo ausentes, fuera de Y-m-d o dateTo anterior a dateFrom.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function trips(ExportTripsReportRequest $request, ReportServiceInterface $reportService)
    {
        try {
            $report = $reportService->downloadTrips(auth('api')->user(), [
                ...$this->filters($request),
                ...$request->validated(),
            ]);

            return response($report['contents'], 200, [
                'Content-Type' => self::XLSX_CONTENT_TYPE,
                'Content-Disposition' => 'attachment; filename="'.$report['fileName'].'"',
            ]);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * The optional filters of `GET /api/trips`, read as strings; `limit` is not read.
     *
     * An array or a nested value comes back as null, so `?status[]=pending` is ignored
     * instead of blowing up inside the service, as in `TripController`.
     *
     * @return array<string, string|null>
     */
    private function filters(Request $request): array
    {
        $filters = [];

        foreach (['status', 'clientId', 'shippingLineId', 'locationId', 'pilotId', 'vehicleId', 'search'] as $key) {
            $value = $request->query($key);
            $filters[$key] = is_string($value) ? $value : null;
        }

        return $filters;
    }
}
