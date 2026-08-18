<?php

namespace App\Http\Resources\Vehicle;

use App\Interfaces\Storage\FileStorageServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Vehicle',
    title: 'Vehículo',
    description: 'Vehículo del inventario de una empresa transportista. El recurso no expone el carrier_id, sino el nombre de la empresa dueña en carrierName: al carrier siempre es la suya, y al administrator le permite leer el listado sin resolver ids a mano.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(
            property: 'plate',
            description: 'Placa del vehículo, siempre normalizada a mayúsculas: si se envía p123abc se persiste y se devuelve P123ABC. Su unicidad es condicional y se valida en el servicio, no en base de datos: solo puede repetirse si todos los vehículos que ya la usan están en inactive.',
            type: 'string',
            maxLength: 15,
            example: 'P123ABC',
        ),
        new OA\Property(property: 'brand', type: 'string', maxLength: 100, example: 'Kenworth'),
        new OA\Property(property: 'model', type: 'string', maxLength: 100, example: 'T680'),
        new OA\Property(property: 'year', type: 'integer', maximum: 2027, minimum: 1900, example: 2021),
        new OA\Property(
            property: 'capacity',
            description: 'Capacidad de carga EN LIBRAS. La unidad es una convención del dominio: no se guarda en base y nada valida que el valor enviado sean libras y no kilos. Viaja como cadena decimal con dos decimales, por el casting decimal:2 del modelo, no como número.',
            type: 'string',
            example: '15000.50',
        ),
        new OA\Property(
            property: 'type',
            description: 'Tipo de vehículo. Los valores viajan en inglés; traducirlos es responsabilidad del cliente.',
            type: 'string',
            enum: ['truck', 'van', 'trailer', 'pickup'],
            example: 'truck',
        ),
        new OA\Property(
            property: 'condition',
            description: 'Condición con la que se adquirió el vehículo. NO ES status: son dos ejes distintos y este recurso devuelve los dos. status es el estado operativo (active, inactive, under_repair), gobierna la baja lógica del DELETE y la unicidad condicional de la placa; condition (new, used) solo dice cómo se compró el vehículo y no gobierna nada. Un vehículo puede estar under_repair y ser new a la vez. Los valores viajan en inglés; traducirlos es responsabilidad del cliente. En vehículos registrados antes de esta versión vale used, que es el valor de relleno de la migración y no un dato capturado.',
            type: 'string',
            enum: ['new', 'used'],
            example: 'used',
        ),
        new OA\Property(
            property: 'kilometersPerGallon',
            description: 'Rendimiento de combustible EN KILÓMETROS POR GALÓN. Viaja como CADENA decimal con dos decimales, por el casting decimal:2 del modelo, no como número: igual que capacity. La unidad es una convención del dominio y no se guarda en base. El backend no lo usa para calcular nada: no alimenta la cotización de fletes ni ningún costo por kilómetro. En vehículos anteriores a esta versión vale "1.00", que es relleno de la migración y no un dato real.',
            type: 'string',
            example: '12.50',
        ),
        new OA\Property(
            property: 'purchasePrice',
            description: 'Valor de compra del vehículo EN QUETZALES (GTQ). Viaja como CADENA decimal con dos decimales (casting decimal:2), no como número. La moneda es una convención del dominio: no se guarda en base ni se valida. Es lo que costó el vehículo y no se recalcula nunca — no hay depreciación ni valor actual. ATENCIÓN: en vehículos anteriores a esta versión vale "1.00" por el default de relleno de la migración, indistinguible de un vehículo que de verdad costó un quetzal; la única marca fiable de ficha sin capturar es engineNumber a null. No se oculta por rol: quien alcanza el listado lo ve en todos los vehículos de su ámbito.',
            type: 'string',
            example: '185000.00',
        ),
        new OA\Property(
            property: 'monthlyInsuranceCost',
            description: 'Costo del seguro EN QUETZALES (GTQ) y POR MES. Las dos cosas son convención del dominio: la columna no guarda ni moneda ni periodicidad. Viaja como CADENA decimal con dos decimales (casting decimal:2), no como número. Es el único dato del seguro que se guarda: no hay aseguradora, póliza, vigencia, deducible ni cobertura. En vehículos anteriores a esta versión vale "1.00" por el default de relleno. Tampoco se oculta por rol.',
            type: 'string',
            example: '1250.00',
        ),
        new OA\Property(
            property: 'mileage',
            description: 'Kilometraje del odómetro EN KILÓMETROS ENTEROS. A diferencia de los tres campos anteriores viaja como ENTERO, no como cadena. 0 es un valor legítimo (vehículo recién comprado). En la edición este campo tiene autorización propia: solo un administrator puede cambiarlo, y puede tanto subirlo como bajarlo. No hay bitácora: el valor anterior no se conserva en ninguna parte. En vehículos anteriores a esta versión vale 1, que es relleno de la migración.',
            type: 'integer',
            example: 120000,
        ),
        new OA\Property(
            property: 'engineNumber',
            description: 'Número de motor, siempre normalizado a MAYÚSCULAS: si se envía abc123 se persiste y se devuelve ABC123. Es NULL en los vehículos registrados antes de esta versión, que no tienen el dato capturado, y el cliente tiene que tolerarlo; por la API no se llega nunca a ese estado, porque el campo es obligatorio en el alta y no se puede vaciar en la edición. Ese null es, de hecho, la única marca fiable de ficha heredada. NO ES ÚNICO: a diferencia de la placa, varios vehículos pueden devolver el mismo engineNumber, así que no sirve como identificador. El filtro engineNumber del listado busca por coincidencia parcial sobre este valor y las filas con null nunca casan con él.',
            type: 'string',
            maxLength: 50,
            nullable: true,
            example: 'ABC123456',
        ),
        new OA\Property(
            property: 'image',
            description: 'URL pública y permanente de la imagen del vehículo, lista para usar como src. La imagen almacenada es siempre un cuadrado de 800x800 px recortado desde el centro del archivo que se subió, en el formato original (jpg o png). Es null cuando el vehículo no tiene imagen. No es la clave interna del objeto: el cliente no debe derivarla ni componerla a mano.',
            type: 'string',
            format: 'uri',
            nullable: true,
            example: 'https://mi-bucket.s3.us-east-1.amazonaws.com/vehicles/9f1b2c3d-4e5f-4a6b-8c7d-0e1f2a3b4c5d.png',
        ),
        new OA\Property(
            property: 'status',
            description: 'Estado operativo. Un vehículo nace siempre en active; under_repair sigue reteniendo su placa y solo inactive la libera. DELETE /api/vehicles/{vehicle} lo pone en inactive sin borrar la fila, por lo que un vehículo desactivado sigue apareciendo en los listados: filtrarlo es responsabilidad del cliente. Hoy el estado es informativo, todavía no bloquea nada.',
            type: 'string',
            enum: ['active', 'inactive', 'under_repair'],
            example: 'active',
        ),
        new OA\Property(
            property: 'carrierName',
            description: 'Nombre de la empresa transportista dueña del vehículo, obtenido de la relación carrier.',
            type: 'string',
            nullable: true,
            example: 'Transportes del Norte',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'VehicleListResponse',
    title: 'Listado de vehículos sin paginar',
    description: 'Respuesta de GET /api/vehicles cuando no se envía limit o cuando el limit no es numérico: se devuelven todos los registros del ámbito y el sobre no incluye total, currentPage ni lastPage. El listado trae por defecto TODOS los estados, incluidos los inactive y los under_repair.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Vehículos obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Vehicle')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedVehicleListResponse',
    title: 'Listado de vehículos paginado',
    description: 'Respuesta de GET /api/vehicles cuando se envía un limit numérico: los metadatos de paginación salen aplanados en la raíz del sobre, no anidados bajo meta.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/VehicleListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class VehicleResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plate' => $this->plate,
            'brand' => $this->brand,
            'model' => $this->model,
            'year' => $this->year,
            'capacity' => $this->capacity,
            'type' => $this->type->value,
            'condition' => $this->condition->value,
            'kilometersPerGallon' => $this->kilometers_per_gallon,
            'purchasePrice' => $this->purchase_price,
            'monthlyInsuranceCost' => $this->monthly_insurance_cost,
            'mileage' => $this->mileage,
            'engineNumber' => $this->engine_number,
            /** Un JsonResource se instancia con new, así que el contrato se resuelve del contenedor. */
            'image' => app(FileStorageServiceInterface::class)->url($this->image),
            'status' => $this->status->value,
            'carrierName' => $this->carrier?->name,
        ];
    }
}
