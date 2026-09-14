<?php

namespace App\Http\Resources\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'VehicleExpensesCountAndAmount',
    title: 'Conteo y monto de un grupo de gastos',
    description: 'Par conteo + monto de un subconjunto de gastos. totalAmount sale SIEMPRE como cadena de dos decimales en GTQ, "0.00" si el grupo está vacío, como amount en el dominio de gastos.',
    properties: [
        new OA\Property(property: 'count', type: 'integer', example: 25),
        new OA\Property(property: 'totalAmount', type: 'string', example: '12000.00'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'VehicleExpensesByNature',
    title: 'Gastos por naturaleza',
    description: 'Las DOS claves de VehicleExpenseNature salen SIEMPRE, con count 0 y totalAmount "0.00" cuando no hay filas. No hay más naturalezas.',
    properties: [
        new OA\Property(property: 'preventive', ref: '#/components/schemas/VehicleExpensesCountAndAmount'),
        new OA\Property(property: 'corrective', ref: '#/components/schemas/VehicleExpensesCountAndAmount'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'VehicleExpensesByCategory',
    title: 'Gastos por categoría',
    description: 'Una fila por categoría de VehicleExpenseCategory con al menos un gasto, con el valor crudo del enum en inglés (tires, brakes, oil_change, …, other), ordenadas por totalAmount descendente. Las categorías sin gastos NO aparecen: el catálogo completo de 22 valores lo conoce el frontend.',
    properties: [
        new OA\Property(property: 'category', type: 'string', example: 'tires'),
        new OA\Property(property: 'count', type: 'integer', example: 10),
        new OA\Property(property: 'totalAmount', type: 'string', example: '8000.00'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'VehicleExpensesByCarrier',
    title: 'Gastos por empresa transportista',
    description: 'Una fila por empresa con al menos un gasto, resuelta con JOIN vehicles → carriers sobre vehicles.carrier_id, ordenadas por totalAmount descendente y, a igual monto, por carrierId ascendente. El status del vehículo NO importa: los gastos de un vehículo inactive cuentan igual, porque el mantenimiento pudo ocurrir antes de la baja. A diferencia del resumen de viajes, aquí TODO gasto tiene empresa, así que la suma de count coincide con count de la raíz.',
    properties: [
        new OA\Property(property: 'carrierId', type: 'integer', example: 3),
        new OA\Property(property: 'carrierName', type: 'string', example: 'TRANSPORTES X'),
        new OA\Property(property: 'count', type: 'integer', example: 20),
        new OA\Property(property: 'totalAmount', type: 'string', example: '7000.00'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'VehicleExpensesByMonth',
    title: 'Gastos por mes',
    description: 'Una fila por mes con al menos un gasto, agrupando expense_date como YYYY-MM y ordenadas por month ASCENDENTE. Los meses sin gastos NO aparecen: el frontend rellena los huecos del rango que pidió.',
    properties: [
        new OA\Property(property: 'month', type: 'string', example: '2026-08'),
        new OA\Property(property: 'count', type: 'integer', example: 15),
        new OA\Property(property: 'totalAmount', type: 'string', example: '5000.00'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'VehicleExpensesSummary',
    title: 'Resumen de gastos de vehículos del tablero',
    description: <<<'TEXT'
    Respuesta de GET /api/dashboard/vehicle-expenses: agregados de vehicle_expenses calculados en vivo, sin tabla ni caché propias. OCHO CLAVES en camelCase y ninguna más, en este orden: totalAmount, count, byCategory, byNature, invoiced, notInvoiced, byCarrier, byMonth.

    TODO EL DINERO SALE COMO CADENA DE DOS DECIMALES en GTQ ("15300.50"), nunca como número: es la convención del proyecto para no perder centavos en el JSON. count es el conteo de filas, un entero.

    ATENCIÓN — byNature, invoiced y notInvoiced LLEVAN SIEMPRE SUS CLAVES, a 0 y "0.00" si no hay filas; byCategory, byCarrier y byMonth SOLO traen filas con datos y salen como [] con la base vacía. Se cumple siempre invoiced.count + notInvoiced.count === count y la suma de sus montos es totalAmount.

    La empresa de un gasto es la de SU VEHÍCULO (vehicles.carrier_id), esté activo, inactivo o en reparación. No hay ningún desglose por vehículo: para eso está GET /api/vehicle-expenses?vehicleId=.
    TEXT,
    properties: [
        new OA\Property(property: 'totalAmount', description: 'Suma de amount de todos los gastos dentro del filtro, como cadena de dos decimales. Es el mismo número que el totalAmount de GET /api/vehicle-expenses sin vehicleId… si ese endpoint lo permitiera; aquí no hace falta vehicleId.', type: 'string', example: '15300.50'),
        new OA\Property(property: 'count', description: 'Número de gastos dentro del filtro.', type: 'integer', example: 42),
        new OA\Property(property: 'byCategory', type: 'array', items: new OA\Items(ref: '#/components/schemas/VehicleExpensesByCategory')),
        new OA\Property(property: 'byNature', ref: '#/components/schemas/VehicleExpensesByNature'),
        new OA\Property(property: 'invoiced', description: 'Gastos con is_invoiced en true.', ref: '#/components/schemas/VehicleExpensesCountAndAmount'),
        new OA\Property(property: 'notInvoiced', description: 'Gastos con is_invoiced en false.', ref: '#/components/schemas/VehicleExpensesCountAndAmount'),
        new OA\Property(property: 'byCarrier', type: 'array', items: new OA\Items(ref: '#/components/schemas/VehicleExpensesByCarrier')),
        new OA\Property(property: 'byMonth', type: 'array', items: new OA\Items(ref: '#/components/schemas/VehicleExpensesByMonth')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'VehicleExpensesSummaryResponse',
    title: 'Sobre del resumen de gastos de vehículos',
    description: 'Sobre habitual del proyecto con el resumen en data. Nunca pagina y nunca devuelve 404: con la base vacía data trae los ocho bloques a cero o vacíos.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Resumen de gastos de vehículos obtenido correctamente'),
        new OA\Property(property: 'data', ref: '#/components/schemas/VehicleExpensesSummary'),
    ],
    type: 'object',
)]
class VehicleExpensesSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The resource wraps the array shaped by
     * `DashboardService::getVehicleExpensesSummary()`, not a model: the keys are listed
     * here so the output order is a contract.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'totalAmount' => $this->resource['totalAmount'],
            'count' => $this->resource['count'],
            'byCategory' => $this->resource['byCategory'],
            'byNature' => $this->resource['byNature'],
            'invoiced' => $this->resource['invoiced'],
            'notInvoiced' => $this->resource['notInvoiced'],
            'byCarrier' => $this->resource['byCarrier'],
            'byMonth' => $this->resource['byMonth'],
        ];
    }
}
