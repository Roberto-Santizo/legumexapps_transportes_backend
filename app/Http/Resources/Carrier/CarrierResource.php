<?php

namespace App\Http\Resources\Carrier;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Carrier',
    title: 'Empresa transportista',
    description: 'Datos de una empresa transportista. El code es el que el carrier comparte con sus pilotos para que se vinculen mediante POST /api/carriers/join.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'Transportes del Norte'),
        new OA\Property(
            property: 'image',
            description: 'Identificador de la imagen: un UUID con la extensión del archivo enviado. ATENCIÓN: hoy el archivo se valida y se descarta, no se guarda en disco ni en la nube, así que este valor NO resuelve a ninguna URL descargable. El cliente no debe construir enlaces con él ni asumir que la imagen quedó almacenada.',
            type: 'string',
            nullable: true,
            example: '9f1b2c3d-4e5f-4a6b-8c7d-0e1f2a3b4c5d.png',
        ),
        new OA\Property(
            property: 'code',
            description: 'Código de la empresa: 6 caracteres alfanuméricos en mayúsculas, único y permanente. No caduca ni se puede rotar.',
            type: 'string',
            maxLength: 6,
            minLength: 6,
            example: 'A7K2QX',
        ),
        new OA\Property(
            property: 'active',
            description: 'Estado de la empresa. Hoy es solo informativo: un active en false no bloquea a los pilotos ni impide que se unan.',
            type: 'boolean',
            example: true,
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'CarrierListResponse',
    title: 'Listado de transportistas sin paginar',
    description: 'Respuesta de GET /api/carriers cuando no se envía limit o cuando el limit no es numérico: se devuelven todos los registros y el sobre no incluye total, currentPage ni lastPage.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Transportistas obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Carrier')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedCarrierListResponse',
    title: 'Listado de transportistas paginado',
    description: 'Respuesta de GET /api/carriers cuando se envía un limit numérico: los metadatos de paginación salen aplanados en la raíz del sobre, no anidados.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/CarrierListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class CarrierResource extends JsonResource
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
            'name' => $this->name,
            'image' => $this->image,
            'code' => $this->code,
            'active' => $this->active,
        ];
    }
}
