<?php

namespace App\Http\Resources\TripEmergencyExpense;

use App\Interfaces\Storage\FileStorageServiceInterface;
use App\Models\TripEmergencyExpense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin TripEmergencyExpense
 */
#[OA\Schema(
    schema: 'TripEmergencyExpense',
    title: 'Gasto emergente de un viaje',
    description: <<<'TEXT'
    Un gasto imprevisto que surgió con el viaje en ruta —una llanta pinchada, una grúa— y que registró la empresa transportista (o el administrador), con un comprobante opcional. NUEVE CLAVES en camelCase y ninguna más, en este orden: id, tripId, amount, description, receiptUrl, receiptType, registeredByName, createdAt y updatedAt.

    ATENCIÓN — NO ES UN VIÁTICO: no hay confirmación del piloto, ni isConfirmed, ni receivedAt, ni confirmedByName. Todo gasto emergente suma en totalAmount desde que se registra.

    ATENCIÓN — amount SALE COMO STRING de dos decimales ("450.00"), no como número: el frontend debe convertirlo antes de sumar. GTQ por convención.

    ATENCIÓN — createdAt y updatedAt NO SON ISO 8601: usan el formato del proyecto d-m-Y h:i:s A. createdAt es la fecha del gasto a efectos de la API (no hay occurred_at); un updatedAt distinto de createdAt delata que el gasto se corrigió.
    TEXT,
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(
            property: 'tripId',
            description: 'Id del viaje al que pertenece. Viaja porque PATCH y DELETE se piden por /api/trip-emergency-expenses/{tripEmergencyExpense}, fuera del viaje.',
            type: 'integer',
            example: 12,
        ),
        new OA\Property(
            property: 'amount',
            description: 'Monto en GTQ como CADENA con dos decimales.',
            type: 'string',
            example: '450.00',
        ),
        new OA\Property(
            property: 'description',
            description: 'Qué pasó, tal como se tecleó (solo trim). Nunca null.',
            type: 'string',
            example: 'Reparación de llanta pinchada en el km 85',
        ),
        new OA\Property(
            property: 'receiptUrl',
            description: 'URL PÚBLICA Y ABSOLUTA del comprobante, o null si no tiene. Enlace directo al almacenamiento: no lleva token y no caduca. La API guarda la key y no la URL, así que no sirve como identificador. Tras un DELETE, o tras reemplazarlo o quitarlo con el PATCH, el archivo anterior deja de existir.',
            type: 'string',
            format: 'uri',
            nullable: true,
            example: 'https://bucket.s3.amazonaws.com/trip-emergency-expenses/9f3a1c2e-4b5d-6e7f-8a9b-0c1d2e3f4a5b.pdf',
        ),
        new OA\Property(
            property: 'receiptType',
            description: 'Extensión real del comprobante: jpg, png o pdf, o null sin comprobante. Es lo que el cliente mira para decidir si pinta una imagen o un enlace. jpeg se guarda siempre como jpg. Derivada de la key, sin columna propia.',
            type: 'string',
            enum: ['jpg', 'png', 'pdf'],
            nullable: true,
            example: 'pdf',
        ),
        new OA\Property(property: 'registeredByName', description: 'Nombre del usuario que registró el gasto. El PATCH no lo cambia.', type: 'string', example: 'Transportes del Sur'),
        new OA\Property(property: 'createdAt', description: 'Fecha de registro, formato d-m-Y h:i:s A.', type: 'string', example: '03-10-2026 02:15:00 PM'),
        new OA\Property(property: 'updatedAt', description: 'Última corrección, formato d-m-Y h:i:s A. Igual a createdAt si nunca se corrigió.', type: 'string', example: '04-10-2026 09:30:00 AM'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripEmergencyExpenseListResponse',
    title: 'Gastos emergentes sin paginar',
    description: <<<'TEXT'
    Respuesta de GET /api/trips/{trip}/emergency-expenses cuando NO se envía limit, o no es numérico: TODOS los gastos emergentes del viaje y el sobre NO incluye total, currentPage ni lastPage. totalAmount SÍ aparece: es dato de negocio, no metadata del paginador.

    Orden FIJO id ASCENDENTE. NO HAY FILTROS. Un viaje sin gastos devuelve 200 con data vacío y totalAmount "0.00", nunca 404.
    TEXT,
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Gastos emergentes obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TripEmergencyExpense')),
        new OA\Property(
            property: 'totalAmount',
            description: 'SUMA de TODOS los gastos emergentes del viaje (no hay confirmación), como CADENA con dos decimales, calculada ANTES de paginar. NO CONFUNDIR CON total, el conteo del paginador. Es el mismo número que totalEmergencyExpensesAmount del detalle del viaje.',
            type: 'string',
            example: '850.00',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedTripEmergencyExpenseListResponse',
    title: 'Gastos emergentes paginados',
    description: 'Respuesta de GET /api/trips/{trip}/emergency-expenses con un limit numérico: total, currentPage y lastPage APLANADOS EN LA RAÍZ, junto a totalAmount. El tamaño de página se acota a [10, 100].',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/TripEmergencyExpenseListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class TripEmergencyExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Nine keys in camelCase. `amount` leaves as a two decimal string —the model does not
     * cast it—, and the receipt is resolved to a public URL plus its derived type, like
     * `invoiceUrl`/`invoiceType` in SPEC 19.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Sí viaja: PATCH y DELETE se piden por /api/trip-emergency-expenses/{id}, fuera del viaje. */
            'tripId' => $this->trip_id,
            'amount' => number_format((float) $this->amount, 2, '.', ''),
            'description' => $this->description,
            /** Service location on purpose: a JsonResource is built with new, so nothing is injected into it. */
            'receiptUrl' => app(FileStorageServiceInterface::class)->url($this->receipt),
            'receiptType' => $this->receiptType(),
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
        ];
    }

    /**
     * Extension of the stored receipt, or null when there is no file.
     *
     * Derived from the key instead of living in its own column, like `invoiceType` in
     * SPEC 19. Lowercase because that is how storeUpload() writes it.
     */
    private function receiptType(): ?string
    {
        if ($this->receipt === null) {
            return null;
        }

        $extension = pathinfo((string) $this->receipt, PATHINFO_EXTENSION);

        return $extension === '' ? null : strtolower($extension);
    }
}
