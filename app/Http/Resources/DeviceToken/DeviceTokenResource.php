<?php

namespace App\Http\Resources\DeviceToken;

use App\Models\UserDeviceToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin UserDeviceToken
 */
#[OA\Schema(
    schema: 'DeviceToken',
    title: 'Token de dispositivo',
    description: <<<'TEXT'
    Un token FCM (Android o iOS) de uno de los dispositivos del usuario autenticado. CINCO CLAVES en camelCase y ninguna más, en este orden: id, token, platform, lastSeenAt, createdAt.

    ATENCIÓN — NO TRAE userId: el dueño es siempre quien hace la petición. Tampoco hay forma de listar los tokens propios: no existe GET /api/device-tokens.

    ATENCIÓN — LAS FECHAS NO SON ISO 8601: usan el formato propio del proyecto d-m-Y h:i:s A y son la hora del servidor.

    Todavía NO SE ENVÍA NINGUNA NOTIFICACIÓN: el token se guarda para una spec futura de envío.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico de la fila (user_device_tokens.id). NO se usa en ninguna ruta: el DELETE va por el token, no por el id.',
            type: 'integer',
            example: 12,
        ),
        new OA\Property(
            property: 'token',
            description: 'El token FCM tal cual se envió: sin normalizar, sensible a mayúsculas, hasta 512 caracteres y sin espacios. Único global.',
            type: 'string',
            example: 'dXk3:APA91bHfake_token-123',
        ),
        new OA\Property(
            property: 'platform',
            description: 'Plataforma del dispositivo, con el valor crudo del enum en inglés.',
            type: 'string',
            enum: ['android', 'ios'],
            example: 'android',
        ),
        new OA\Property(
            property: 'lastSeenAt',
            description: 'Última vez que el dispositivo registró el token (hora del servidor en cada POST), formato d-m-Y h:i:s A.',
            type: 'string',
            example: '23-09-2026 10:15:02 AM',
        ),
        new OA\Property(
            property: 'createdAt',
            description: 'Fecha de creación de la fila, formato d-m-Y h:i:s A. Una reasignación a otro usuario NO la cambia.',
            type: 'string',
            example: '23-09-2026 10:15:02 AM',
        ),
    ],
    type: 'object',
)]
class DeviceTokenResource extends JsonResource
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
            'token' => $this->token,
            'platform' => $this->platform?->value,
            /** Sin userId: el dueño es siempre quien hace la petición. */
            'lastSeenAt' => $this->last_seen_at?->format('d-m-Y h:i:s A'),
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
