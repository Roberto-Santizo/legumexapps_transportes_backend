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
            property: 'image',
            description: 'Identificador de la imagen: un UUID con la extensión del archivo enviado. ATENCIÓN: hoy el archivo se valida y se descarta, no se guarda en disco ni en la nube, así que este valor NO resuelve a ninguna URL descargable. El cliente no debe construir enlaces con él ni asumir que la imagen quedó almacenada. Es la misma deuda declarada en la SPEC 03.',
            type: 'string',
            nullable: true,
            example: '9f1b2c3d-4e5f-4a6b-8c7d-0e1f2a3b4c5d.png',
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
            /** Un JsonResource se instancia con new, así que el contrato se resuelve del contenedor. */
            'image' => app(FileStorageServiceInterface::class)->url($this->image),
            'status' => $this->status->value,
            'carrierName' => $this->carrier?->name,
        ];
    }
}
