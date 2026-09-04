<?php

namespace App\Http\Resources\Auth;

use App\Interfaces\Storage\FileStorageServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'User',
    title: 'Usuario',
    description: <<<'TEXT'
    Datos públicos de un usuario: 10 CLAVES en camelCase. NUNCA incluye la contraseña ni el código de confirmación. Es la misma forma en los tres endpoints que lo devuelven —el data de POST /api/auth/register y el data.user de POST /api/auth/login y de GET /api/auth/check-status—, así que un cliente puede reutilizar el mismo tipo en los tres.

    ATENCIÓN — carrierId, carrierName y carrierCode SE RESUELVEN CONTRA LA BASE EN CADA RESPUESTA, no se copian del token. El JWT lleva claims con esos mismos nombres, pero pueden ir hasta una hora obsoletos: son informativos para el frontend y NUNCA fuente de verdad para autorizar. Las tres viajan juntas o las tres en null.

    Desde SPEC 25 trae además dpiImage y licenseImage, las dos fotos del alta del piloto. Son null para cualquier rol que no sea pilot y también para los pilotos registrados antes de esa versión, porque no hubo backfill.
    TEXT,
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
            property: 'carrierId',
            description: 'Identificador de la empresa transportista con la que el usuario está vinculado en ESTE MOMENTO, sea como dueño (rol carrier) o como piloto vinculado por POST /api/carriers/join. Es null mientras no tenga ninguna, y ese es el estado normal de un administrator o de un manager, y también el de un piloto recién registrado. ATENCIÓN — SE RESUELVE CONSULTANDO LA BASE EN CADA RESPUESTA, no se lee del token: el claim carrierId del JWT lleva el mismo nombre pero puede estar hasta una hora obsoleto y NUNCA debe usarse para autorizar.',
            type: 'integer',
            nullable: true,
            example: 4,
        ),
        new OA\Property(
            property: 'carrierName',
            description: 'Nombre de la empresa transportista vinculada, para pintarlo sin un segundo GET. null cuando carrierId es null; las tres claves de empresa viajan siempre juntas o las tres en null.',
            type: 'string',
            nullable: true,
            example: 'Transportes del Norte',
        ),
        new OA\Property(
            property: 'carrierCode',
            description: 'Código de 6 caracteres [A-Z0-9] de la empresa vinculada. Es el código que un piloto envía en POST /api/carriers/join para unirse, así que el dueño lo lee de aquí para compartirlo. null cuando el usuario no tiene empresa.',
            type: 'string',
            nullable: true,
            example: 'K7B2QX',
        ),
        new OA\Property(
            property: 'emailVerifiedAt',
            description: 'Fecha de confirmación de la cuenta. Es null mientras la cuenta no haya sido confirmada.',
            type: 'string',
            format: 'date-time',
            nullable: true,
            example: '2026-07-31T10:15:00.000000Z',
        ),
        new OA\Property(
            property: 'dpiImage',
            description: 'URL pública y permanente de la foto del ANVERSO del DPI, lista para usar como src, o null. Solo la tienen los pilotos registrados desde SPEC 25: para un carrier, un administrator o un manager es SIEMPRE null, y también lo es para un piloto anterior a esa versión, porque no hubo backfill. Se sube una sola vez en POST /api/auth/register y NO SE PUEDE REEMPLAZAR: ningún endpoint la acepta después. La URL es pública igual que la imagen de un vehículo: quien tenga el enlace abre el documento SIN TOKEN. No es la clave interna del objeto y el cliente no debe componerla a mano.',
            type: 'string',
            nullable: true,
            example: 'https://bucket.s3.amazonaws.com/pilot-documents/9f3a2c1d-8b4e-4a70-9c21-5d6e7f801a2b.jpg',
        ),
        new OA\Property(
            property: 'licenseImage',
            description: 'URL pública y permanente de la foto del ANVERSO de la licencia de conducir, con las mismas reglas que dpiImage: null salvo para un piloto registrado desde SPEC 25, y las dos vienen siempre juntas —no existe una fila con una sola foto—. La API NO guarda ningún otro dato de la licencia: ni número, ni tipo, ni fecha de vencimiento, así que desde aquí no se puede saber si está caducada.',
            type: 'string',
            nullable: true,
            example: 'https://bucket.s3.amazonaws.com/pilot-documents/1c07f4d9-2e35-4b18-8a6f-3b9c0d1e2f34.png',
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
            /**
             * Las dos fotos del piloto, resueltas a URL absoluta como en VehicleResource:
             * localización de servicio consciente, porque un JsonResource se instancia con
             * `new`. `url(null)` devuelve null por contrato, así que la ausencia de fila
             * —cualquier otro rol, o un piloto anterior a SPEC 25— no necesita más que el
             * operador nullsafe sobre la relación.
             */
            'dpiImage' => app(FileStorageServiceInterface::class)->url($this->pilotDocument?->dpi_image),
            'licenseImage' => app(FileStorageServiceInterface::class)->url($this->pilotDocument?->license_image),
        ];
    }
}
