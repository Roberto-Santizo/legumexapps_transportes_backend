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

    COLUMNAS POR ROL — 14 para administrator, manager, export y shipment, con UNA FILA POR LÍNEA DE PRODUCTO; 12 para carrier y user (sin «Producto» ni «Cajas»), una fila por viaje. Ningún rol recibe columnas de dinero. carrier y user NO reciben los productos aunque GET /api/trip-finished-products sí les responda: el reporte tiene su propia matriz.

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

        COLUMNAS (16, en este orden): Orden, OV, PO, Cliente, Producto, Cajas, Naviera, Destino, Contenedor, Punto de Partida, Fecha de recolección, Fecha de embarque, Booking, Punto de llegada, Transporte, Status. «OV» es la orden de venta (ov) y «PO» la referencia comercial (reference), ambas opcionales y para todos los roles. «Destino» es el destino final en texto libre (destination); «Punto de llegada» es el puerto (location). Status en español (Pendiente / En ruta / Finalizado / Cancelado); fechas en d-m-Y h:i:s A (NO ISO 8601); cualquier null es una celda vacía (un viaje anterior a SPEC 41 trae vacío Booking).

        «Producto» (nombre del producto terminado) y «Cajas» (número) SOLO PARA administrator, manager, export Y shipment, y el archivo trae UNA FILA POR LÍNEA DE PRODUCTO: un viaje con N líneas ocupa N filas consecutivas, en el orden de las líneas y con los datos del viaje repetidos en cada una; un viaje sin líneas ocupa una fila con las dos celdas vacías; un SKU borrado sigue saliendo. Para carrier y user las dos columnas no existen (14 columnas) y hay una fila por viaje. El tope de 5000 cuenta viajes, no filas.

        La fila de encabezados sale con relleno azul oscuro, texto blanco en negrita, centrada, inmovilizada y con autofiltro; cada columna se ajusta al ancho de su contenido.

        Un rango sin viajes responde 200 con un archivo que solo trae la fila de encabezados. Con MÁS DE 5000 viajes responde 400 y no genera archivo: acota el rango.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Reports'],
        parameters: [
            new OA\Parameter(name: 'dateFrom', description: 'Primer día del rango sobre recolection_date, inclusive. OBLIGATORIO, Y-m-d estricto.', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-01')),
            new OA\Parameter(name: 'dateTo', description: 'Último día del rango, inclusive y por día completo. OBLIGATORIO, Y-m-d estricto y no anterior a dateFrom. Sin rango máximo.', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-09-30')),
            new OA\Parameter(name: 'status', description: 'Opcional y TOLERANTE: pending, in_route, finished o cancelled; cualquier otro valor se ignora.', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'in_route', 'finished', 'cancelled'])),
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
