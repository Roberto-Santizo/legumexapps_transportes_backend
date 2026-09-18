<?php

namespace App\Http\Resources\TripExpense;

use App\Models\TripExpense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin TripExpense
 */
#[OA\Schema(
    schema: 'TripExpense',
    title: 'Viático de un viaje',
    description: <<<'TEXT'
    Un viático (dinero para gastos del camino) que la empresa transportista entregó al piloto de un viaje y que ese piloto confirma haber recibido. OCHO CLAVES en camelCase y ninguna más, en este orden: id, tripId, amount, description, isConfirmed, receivedAt, confirmedByName y registeredByName. Es el CALCO de TripFuel (SPEC 27) con dinero en vez de galones.

    ATENCIÓN — SÍ TRAE tripId: la confirmación se pide por PATCH /api/trip-expenses/{tripExpense}/confirm, FUERA del viaje, así que la respuesta de esa llamada tiene que decir a qué viaje pertenece el viático.

    ATENCIÓN — EL CICLO DE VIDA SON DOS ESTADOS Y NO HAY COLUMNA status. isConfirmed es un campo DERIVADO de receivedAt —no existe en la base— y las dos claves cuentan lo mismo: sin confirmar es isConfirmed en false, receivedAt en null y confirmedByName en null; confirmado es isConfirmed en true con las otras dos puestas. No hay un tercer estado, ni rechazo, ni cancelación, y NO SE PUEDE DESCONFIRMAR: receivedAt no vuelve nunca a null.

    ATENCIÓN — amount SALE COMO STRING de dos decimales, no como número ("350.00"), igual que gallons en SPEC 27: el modelo NO castea la columna a propósito. El frontend debe convertirlo antes de sumar (parseFloat). Es dinero en GTQ por convención: no hay columna de moneda.

    ATENCIÓN — receivedAt NO ES ISO 8601: usa el formato propio del proyecto d-m-Y h:i:s A. Es la HORA DEL SERVIDOR en el momento de confirmar, no la del dispositivo del piloto ni la hora real en que se entregó el dinero.

    ATENCIÓN — ESTE VIÁTICO ES INMUTABLE Y ETERNO. No hay PATCH ni DELETE: la tabla es APPEND-ONLY, como trip_fuels y trip_positions. Un monto mal tecleado se queda para siempre y no se puede compensar, porque el monto no admite negativos; solo se arregla tocando la base. El DELETE (baja lógica) del viaje tampoco borra ninguna fila.

    NO SE GUARDA NINGÚN COMPROBANTE: ni factura, ni imagen, ni categoría, ni cantidad REALMENTE recibida distinta de la entregada: el piloto confirma o no confirma, y no reporta una cantidad propia. description es el único texto libre.

    De los dos autores solo salen los NOMBRES, sin id: registeredByName es el usuario de la empresa que registró el viático —en la primera fila, quien asignó el viaje— y confirmedByName es el piloto que lo confirmó.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico del viático (trip_expenses.id). ES EL PARÁMETRO {tripExpense} de PATCH /api/trip-expenses/{tripExpense}/confirm —la única ruta del proyecto que apunta a un viático— y también el criterio de orden del listado (id ASC). NO hay ninguna ruta de detalle /api/trip-expenses/{tripExpense}: para ver un viático se lista el viaje.',
            type: 'integer',
            example: 12,
        ),
        new OA\Property(
            property: 'tripId',
            description: 'Id del viaje al que pertenece el viático (trips.id). Viaja SIEMPRE, aunque en el listado sea redundante con la URL, porque la confirmación se pide desde /api/trip-expenses/{tripExpense}/confirm y ahí es el único sitio donde aparece el viaje. Es INMUTABLE.',
            type: 'integer',
            example: 1,
        ),
        new OA\Property(
            property: 'amount',
            description: 'Monto del viático en GTQ, SIEMPRE COMO STRING de dos decimales (decimal(10,2) en la base, sin cast en el modelo). Es la cantidad ENTREGADA por la empresa: no existe un segundo número que cuadrar. ATENCIÓN — solo suma en totalAmount y en totalExpensesAmount CUANDO EL VIÁTICO ESTÁ CONFIRMADO.',
            type: 'string',
            example: '350.00',
        ),
        new OA\Property(
            property: 'description',
            description: 'Concepto del viático, tal como se tecleó (solo trim). null cuando no se mandó o se mandó en blanco. Texto libre: sin catálogo, sin categoría y sin filtro.',
            type: 'string',
            nullable: true,
            example: 'Alimentación y peajes',
        ),
        new OA\Property(
            property: 'isConfirmed',
            description: 'Si el piloto asignado ya dio fe de haber recibido el dinero. CAMPO DERIVADO, SIN COLUMNA: es exactamente «receivedAt distinto de null». false es el estado en que NACE todo viático, incluido el que crea /assignment. ATENCIÓN — NO SE PUEDE FILTRAR POR ÉL: separar confirmados de pendientes es trabajo del frontend.',
            type: 'boolean',
            example: true,
        ),
        new OA\Property(
            property: 'receivedAt',
            description: 'Momento en que el piloto CONFIRMÓ el viático, con el formato propio del proyecto d-m-Y h:i:s A, NO en ISO 8601. null mientras no se confirme. La pone el now() del SERVIDOR y no se acepta desde ningún cuerpo. SE ESCRIBE UNA SOLA VEZ Y NO SE PISA: reconfirmar responde 200 devolviendo esta misma fecha original, sin escribir nada.',
            type: 'string',
            nullable: true,
            example: '18-09-2026 07:42:18 AM',
        ),
        new OA\Property(
            property: 'confirmedByName',
            description: 'Nombre del piloto que confirmó el viático, resuelto desde la relación. null mientras no se confirme, y se escribe SIEMPRE junto a receivedAt, nunca una sin la otra. Sale solo el nombre, sin id.',
            type: 'string',
            nullable: true,
            example: 'Juan Pérez',
        ),
        new OA\Property(
            property: 'registeredByName',
            description: 'Nombre del usuario de la empresa transportista que registró el viático, resuelto desde la relación. En el PRIMER viático de un viaje suele ser quien lo asignó, porque lo crea PATCH /api/trips/{trip}/assignment dentro de su propia transacción. Sale solo el nombre, sin id, y sale siempre del usuario autenticado.',
            type: 'string',
            nullable: true,
            example: 'María López',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripExpenseListResponse',
    title: 'Viáticos sin paginar',
    description: <<<'TEXT'
    Respuesta de GET /api/trips/{trip}/expenses cuando NO se envía limit, o cuando el limit no es numérico: se devuelven TODOS los viáticos del viaje y el sobre NO incluye total, currentPage ni lastPage.

    totalAmount SÍ aparece en esta forma, aunque no haya paginación: es dato de negocio y no metadata del paginador, con el precedente literal de totalGallons en SPEC 27 y totalAmount en SPEC 14.

    El orden es FIJO id ASCENDENTE y NO es configurable. NO HAY NI UN SOLO FILTRO: cualquier query param que no sea limit o page se ignora. Devuelve los CONFIRMADOS Y LOS PENDIENTES mezclados, y distinguirlos es trabajo del frontend con la clave isConfirmed.

    Un viaje sin ningún viático devuelve 200 con data vacío y totalAmount "0.00", NUNCA 404.
    TEXT,
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Viáticos obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TripExpense')),
        new OA\Property(
            property: 'totalAmount',
            description: 'SUMA de los montos de los viáticos CONFIRMADOS del viaje, como CADENA con dos decimales. ATENCIÓN — SOLO LOS CONFIRMADOS: un viático registrado y no confirmado NO suma, así que un viaje recién asignado con viático devuelve "0.00" teniendo ya una fila en data con isConfirmed en false. NO CONFUNDIR CON total: total es el CONTEO de filas que aporta el paginador y solo aparece al paginar; totalAmount aparece SIEMPRE, con y sin limit, y se calcula ANTES de paginar. Es el MISMO número que el totalExpensesAmount del detalle del viaje.',
            type: 'string',
            example: '850.00',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedTripExpenseListResponse',
    title: 'Viáticos paginados',
    description: <<<'TEXT'
    Respuesta de GET /api/trips/{trip}/expenses cuando se envía un limit numérico: los metadatos de paginación salen APLANADOS EN LA RAÍZ del sobre, junto a statusCode, message, data y totalAmount, NO anidados bajo meta.

    ATENCIÓN — en esta forma conviven en la misma raíz totalAmount y total, y NO significan lo mismo: total es el CONTEO de viáticos del viaje (un entero) y totalAmount es la SUMA en GTQ de los CONFIRMADOS (una cadena con dos decimales).

    ATENCIÓN — EL TAMAÑO DE PÁGINA SE ACOTA A [10, 100], a diferencia de GET /api/trips: limit=1 y limit=5 devuelven páginas de 10, y limit=500 devuelve páginas de 100. El orden sigue siendo id ASCENDENTE.
    TEXT,
    allOf: [
        new OA\Schema(ref: '#/components/schemas/TripExpenseListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class TripExpenseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Eight keys in camelCase, the mirror of `TripFuelResource`. `isConfirmed` is
     * **derived** from `received_at` and has no column of its own: the two states of an
     * allowance are «unconfirmed» and «received», and a `status` column could only end
     * up contradicting the date.
     *
     * `amount` leaves as a two decimal string —the model deliberately does not cast it—,
     * like `gallons` in SPEC 27 and `price` in SPEC 17.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Sí viaja: la confirmación se pide por /api/trip-expenses/{tripExpense}, fuera del viaje. */
            'tripId' => $this->trip_id,
            'amount' => number_format((float) $this->amount, 2, '.', ''),
            'description' => $this->description,
            /** Derivado, sin columna: null en `received_at` es «sin confirmar» y nada más. */
            'isConfirmed' => $this->received_at !== null,
            /** El formato de fecha del resto del dominio, nunca ISO 8601. */
            'receivedAt' => $this->received_at?->format('d-m-Y h:i:s A'),
            'confirmedByName' => $this->confirmedBy?->name,
            'registeredByName' => $this->registeredBy?->name,
        ];
    }
}
