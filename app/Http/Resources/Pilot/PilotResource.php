<?php

namespace App\Http\Resources\Pilot;

use App\Interfaces\Storage\FileStorageServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Pilot',
    title: 'Piloto con salario',
    description: <<<'TEXT'
    Piloto vinculado a una empresa transportista, con su salario base vigente. Envuelve una fila de la pivote carrier_pilots con su usuario y su empresa ya resueltos, así que el recurso mezcla tres tablas y no se corresponde con ningún modelo suelto.

    ATENCIÓN — EL id ES EL user_id DEL PILOTO, no el id de la fila de carrier_pilots. El id del pivote NO SALE NUNCA de la API: no aparece aquí, no aparece en el historial y no hay forma de obtenerlo. Es el user_id el que viaja en el parámetro {pilot} de PATCH /api/pilots/{pilot}/salary y de GET /api/pilots/{pilot}/salary-history.

    ATENCIÓN — EXISTEN DOS LISTADOS DE PILOTOS y devuelven cosas distintas. GET /api/carriers/me/pilots (SPEC 03) es el listado del TRANSPORTISTA: solo sus propios pilotos, SIN el campo salary y con joinedAt en ISO 8601. GET /api/pilots —este recurso— es el listado de ADMINISTRACIÓN: incluye salary, usa el formato de fecha propio d-m-Y h:i:s A y admite el filtro carrierId para administrator y manager. No se han unificado a propósito; el de SPEC 03 se mantiene intacto.

    El salario NO se puede modificar desde aquí: este recurso es solo de lectura y la única escritura del dominio es PATCH /api/pilots/{pilot}/salary. Tampoco existen show, store, update ni destroy: el dominio tiene TRES endpoints y ninguno más, y vincular un piloto sigue siendo POST /api/carriers/join.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador del USUARIO piloto (users.id), NO el id de la fila de carrier_pilots. Es el número que el front ya tiene en pantalla y el que se manda como {pilot} en las otras dos rutas del dominio. Coincide con el id que devuelve GET /api/carriers/me/pilots, así que los dos listados son cruzables por este campo.',
            type: 'integer',
            example: 12,
        ),
        new OA\Property(
            property: 'name',
            description: 'Nombre del piloto, resuelto por la relación con users. Es null solo si la relación no se pudo cargar; en la práctica siempre viaja poblado, porque la FK a users no admite huérfanos.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'email',
            description: 'Correo del piloto, resuelto por la relación con users. Es el mismo con el que inicia sesión. Este dominio no lo modifica ni lo valida: se muestra tal como está en users.',
            type: 'string',
            format: 'email',
            nullable: true,
            example: 'piloto@legumex.com',
        ),
        new OA\Property(
            property: 'carrierId',
            description: 'Identificador de la empresa transportista a la que está vinculado el piloto (carriers.id). Un piloto pertenece como mucho a UNA empresa —hay índice único sobre carrier_pilots.user_id—, así que este campo nunca es una lista. Es también el valor que se manda en el filtro carrierId del listado, que solo surte efecto para administrator y manager.',
            type: 'integer',
            example: 4,
        ),
        new OA\Property(
            property: 'carrierName',
            description: 'Nombre de la empresa transportista, resuelto por relación para que el cliente pinte la tabla sin un segundo GET. Es null solo si la relación no se pudo cargar.',
            type: 'string',
            nullable: true,
            example: 'Transportes del Norte',
        ),
        new OA\Property(
            property: 'salary',
            description: 'Salario base MENSUAL del piloto EN QUETZALES (GTQ), como CADENA con DOS decimales por el cast decimal:2 — nunca como número JSON. La columna decimal(10,2) no declara ni la unidad ni la periodicidad: que sea mensual y en quetzales es convención del dominio y solo lo dice esta documentación. ATENCIÓN — null NO significa "gana cero": significa "todavía no se le ha asignado salario". Un piloto que se une con POST /api/carriers/join nace con null, y el PATCH valida min:0.01 precisamente para que no exista un 0.00 que se confunda con lo uno o con lo otro. Solo cambia por PATCH /api/pilots/{pilot}/salary, y cada cambio deja una fila en el historial.',
            type: 'string',
            nullable: true,
            example: '4500.00',
        ),
        new OA\Property(
            property: 'joinedAt',
            description: 'Fecha en que el piloto se vinculó a la empresa (created_at de carrier_pilots), NO la fecha de alta de su usuario ni la del último cambio de salario. ATENCIÓN — igual que en Products, Zones y FreightRates: NO viaja en ISO 8601, sino con el formato propio d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM). Por eso se documenta como string SIN format date-time: un cliente generado desde este schema que intentara parsearlo como ISO fallaría. Ojo, el joinedAt de GET /api/carriers/me/pilots SÍ es ISO 8601: son dos Resources distintos y solo este adopta la convención nueva.',
            type: 'string',
            nullable: true,
            example: '04-08-2026 10:15:00 AM',
        ),
        new OA\Property(
            property: 'dpiImage',
            description: 'URL pública y permanente de la foto del ANVERSO del DPI del piloto, lista para usar como src, o null. Se sube UNA SOLA VEZ en POST /api/auth/register y no se puede reemplazar: este dominio NO la escribe —su única escritura sigue siendo el salario— y no existe ningún endpoint que la acepte. Es null para los pilotos registrados antes de SPEC 25, porque no hubo backfill, y NO BLOQUEA NADA: un piloto sin documentos cobra salario y opera viajes con normalidad. Tampoco se puede filtrar por ella: no hay ningún ?hasDocuments=. ATENCIÓN — la URL es pública: quien tenga el enlace abre el documento SIN TOKEN.',
            type: 'string',
            nullable: true,
            example: 'https://bucket.s3.amazonaws.com/pilot-documents/9f3a2c1d-8b4e-4a70-9c21-5d6e7f801a2b.jpg',
        ),
        new OA\Property(
            property: 'licenseImage',
            description: 'URL pública y permanente de la foto del ANVERSO de la licencia de conducir, con las mismas reglas que dpiImage: las dos vienen siempre juntas o las dos vienen en null, porque no existe una fila de documentos a medias. Ojo — GET /api/carriers/me/pilots (SPEC 03) NO trae estas dos claves: son dos listados distintos a propósito, y solo este, el de administración, las incluye.',
            type: 'string',
            nullable: true,
            example: 'https://bucket.s3.amazonaws.com/pilot-documents/1c07f4d9-2e35-4b18-8a6f-3b9c0d1e2f34.png',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PilotListResponse',
    title: 'Listado de pilotos sin paginar',
    description: 'Respuesta de GET /api/pilots cuando no se envía limit o cuando el limit no es numérico: se devuelven todos los pilotos que el ámbito del usuario permita ver y el sobre NO incluye total, currentPage ni lastPage. Es la forma que quiere un selector que necesita la lista entera. El orden es fijo, id ASC de la fila pivote, es decir el orden en que los pilotos se fueron uniendo.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Pilotos obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Pilot')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedPilotListResponse',
    title: 'Listado de pilotos paginado',
    description: 'Respuesta de GET /api/pilots cuando se envía un limit numérico: los metadatos de paginación salen APLANADOS en la raíz del sobre, junto a statusCode, message y data, no anidados bajo meta.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/PilotListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class PilotResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Wraps a carrier_pilots row with its user and carrier already loaded. The exposed
     * id is the pilot's user_id — the identifier the client already has on screen and
     * the one that travels in {pilot} — never the id of the pivot row.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user_id,
            'name' => $this->user?->name,
            'email' => $this->user?->email,
            'carrierId' => $this->carrier_id,
            'carrierName' => $this->carrier?->name,
            /** Monthly base salary in GTQ; null means it has not been assigned yet. */
            'salary' => $this->salary,
            'joinedAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            /**
             * Las dos fotos del alta, resueltas a URL absoluta a través del usuario. Este
             * dominio no las escribe nunca: solo las lee. `url(null)` devuelve null por
             * contrato, así que un piloto anterior a SPEC 25 sale con las dos en null.
             */
            'dpiImage' => app(FileStorageServiceInterface::class)->url($this->user?->pilotDocument?->dpi_image),
            'licenseImage' => app(FileStorageServiceInterface::class)->url($this->user?->pilotDocument?->license_image),
        ];
    }
}
