<?php

namespace App\Http\Resources\Carrier;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'CarrierPilot',
    title: 'Piloto vinculado',
    description: 'Piloto vinculado a una empresa transportista. Es un usuario con rol pilot más la fecha en que se unió.',
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador del usuario piloto, no el de la fila del pivote carrier_pilots.',
            type: 'integer',
            example: 7,
        ),
        new OA\Property(property: 'name', type: 'string', example: 'Roberto Santizo'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'piloto@legumex.com'),
        new OA\Property(
            property: 'joinedAt',
            description: 'Fecha en la que el piloto se vinculó a la empresa, tomada de carrier_pilots.created_at.',
            type: 'string',
            format: 'date-time',
            nullable: true,
            example: '2026-08-04T10:15:00.000000Z',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'CarrierPilotListResponse',
    title: 'Listado de pilotos sin paginar',
    description: 'Respuesta de GET /api/carriers/me/pilots cuando no se envía limit o cuando el limit no es numérico: se devuelven todos los pilotos y el sobre no incluye total, currentPage ni lastPage.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Pilotos obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CarrierPilot')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedCarrierPilotListResponse',
    title: 'Listado de pilotos paginado',
    description: 'Respuesta de GET /api/carriers/me/pilots cuando se envía un limit numérico: los metadatos de paginación salen aplanados en la raíz del sobre, no anidados.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/CarrierPilotListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class CarrierPilotResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The joined date comes from the carrier_pilots pivot row.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'joinedAt' => $this->pivot?->created_at,
        ];
    }
}
