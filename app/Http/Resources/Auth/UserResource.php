<?php

namespace App\Http\Resources\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'User',
    title: 'Usuario',
    description: 'Datos públicos de un usuario. Nunca incluye la contraseña.',
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(property: 'name', type: 'string', example: 'Roberto Santizo'),
        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'piloto@legumex.com'),
        new OA\Property(
            property: 'role',
            description: 'Rol del usuario dentro del sistema.',
            type: 'string',
            enum: ['administrator', 'carrier', 'pilot', 'manager'],
            example: 'pilot',
        ),
        new OA\Property(
            property: 'emailVerifiedAt',
            description: 'Fecha de confirmación de la cuenta. Es null mientras la cuenta no haya sido confirmada.',
            type: 'string',
            format: 'date-time',
            nullable: true,
            example: '2026-07-31T10:15:00.000000Z',
        ),
    ],
    type: 'object',
)]
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $carrier = $this->currentCarrier();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'carrierId' => $carrier?->id,
            'carrierName' => $carrier?->name,
            'carrierCode' => $carrier?->code,
            'emailVerifiedAt' => $this->email_verified_at,
        ];
    }
}
