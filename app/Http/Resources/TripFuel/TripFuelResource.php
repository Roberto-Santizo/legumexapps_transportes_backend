<?php

namespace App\Http\Resources\TripFuel;

use App\Models\TripFuel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin TripFuel
 */
#[OA\Schema(
    schema: 'TripFuel',
    title: 'Carga de combustible de un viaje',
    description: <<<'TEXT'
    Una carga de combustible que la empresa transportista asignó a un viaje y que su piloto confirma. OCHO CLAVES en camelCase y ninguna más, en este orden: id, tripId, gallons, fuelType, isConfirmed, loadedAt, confirmedByName y registeredByName.

    ATENCIÓN — SÍ TRAE tripId, al contrario que el punto del rastro de SPEC 26: la confirmación se pide por PATCH /api/trip-fuels/{tripFuel}/confirm, FUERA del viaje, así que la respuesta de esa llamada tiene que decir a qué viaje pertenece la carga.

    ATENCIÓN — EL CICLO DE VIDA SON DOS ESTADOS Y NO HAY COLUMNA status. isConfirmed es un campo DERIVADO de loadedAt —no existe en la base— y las dos claves cuentan lo mismo: sin confirmar es isConfirmed en false, loadedAt en null y confirmedByName en null; confirmada es isConfirmed en true con las otras dos puestas. No hay un tercer estado, ni rechazo, ni cancelación, y NO SE PUEDE DESCONFIRMAR: loadedAt no vuelve nunca a null.

    ATENCIÓN — gallons SALE COMO STRING de dos decimales, no como número ("20.00", "45.50"), igual que price en SPEC 17 y salary en SPEC 11: el modelo NO castea la columna a propósito. El frontend debe convertirlo antes de sumar (parseFloat) y no comparar cargas por igualdad de cadena.

    ATENCIÓN — loadedAt NO ES ISO 8601: usa el formato propio del proyecto d-m-Y h:i:s A, como el resto del dominio de viajes. Es la HORA DEL SERVIDOR en el momento de confirmar, no la del dispositivo del piloto ni la hora real en que se echó el combustible: si el piloto confirma al día siguiente, la fecha es la del día siguiente.

    ATENCIÓN — ESTA CARGA ES INMUTABLE Y ETERNA. No hay PATCH ni DELETE de una carga: la tabla es APPEND-ONLY, como trip_positions. Una cantidad mal tecleada se queda para siempre y no se puede compensar, porque los galones no admiten negativos; solo se arregla tocando la base. El DELETE (baja lógica) del viaje tampoco borra ninguna fila.

    NO SE GUARDA NINGÚN DINERO: no hay precio unitario, ni costo total, ni moneda, ni proveedor, ni número de factura, ni imagen del cupón. fuelType no se cruza con fuel_prices (SPEC 06) y ningún precio vigente se congela aquí. Tampoco hay galones REALMENTE recibidos distintos de los asignados: el piloto confirma o no confirma, y no reporta una cantidad propia ni una observación.

    De los dos autores solo salen los NOMBRES, sin id: registeredByName es el usuario de la empresa que registró la carga —en la primera fila, quien asignó el viaje— y confirmedByName es el piloto que la confirmó.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico de la carga (trip_fuels.id). ES EL PARÁMETRO {tripFuel} de PATCH /api/trip-fuels/{tripFuel}/confirm —la única ruta del proyecto que apunta a una carga— y también el criterio de orden del listado (id ASC), que es la cronología real de registro. NO hay ninguna ruta de detalle /api/trip-fuels/{tripFuel}: para ver una carga se lista el viaje.',
            type: 'integer',
            example: 37,
        ),
        new OA\Property(
            property: 'tripId',
            description: 'Id del viaje al que pertenece la carga (trips.id). Viaja SIEMPRE, aunque en el listado sea redundante con la URL, porque la confirmación se pide desde /api/trip-fuels/{tripFuel}/confirm y ahí es el único sitio donde aparece el viaje. Es INMUTABLE: una carga no se traslada de un viaje a otro por ninguna vía.',
            type: 'integer',
            example: 1,
        ),
        new OA\Property(
            property: 'gallons',
            description: 'Galones de esta carga, SIEMPRE COMO STRING de dos decimales (decimal(8,2) en la base, sin cast en el modelo). Es la cantidad ASIGNADA por la empresa, no la medida por el piloto: no existe un segundo número que cuadrar. ATENCIÓN — solo suma en totalGallons y en totalFuelGallons CUANDO LA CARGA ESTÁ CONFIRMADA. No se valida contra nada más que min:0.01, así que una cantidad absurda se guarda igual y, sin PATCH ni DELETE, se queda para siempre.',
            type: 'string',
            example: '45.50',
        ),
        new OA\Property(
            property: 'fuelType',
            description: 'Tipo de combustible de esta carga, con el VALOR CRUDO DEL ENUM EN INGLÉS y sin traducir —traducirlo es cosa del frontend—, como LocationType en SPEC 21 y TripStatus en SPEC 24. Es POR CARGA, no por viaje: dos cargas del mismo viaje pueden diferir. ATENCIÓN — no está respaldado por fuel_prices: no se exigió que el tipo tuviera precio vigente al registrarlo y no se guardó ningún precio.',
            type: 'string',
            enum: ['regular', 'premium', 'diesel', 'diesel_premium'],
            example: 'diesel',
        ),
        new OA\Property(
            property: 'isConfirmed',
            description: 'Si el piloto asignado ya dio fe de haber recibido la carga. CAMPO DERIVADO, SIN COLUMNA: es exactamente «loadedAt distinto de null», igual que currentValue se deriva en SPEC 17. false es el estado en que NACE toda carga, incluida la que crea /assignment. ATENCIÓN — NO SE PUEDE FILTRAR POR ÉL: el listado no acepta ningún parámetro de estado de confirmación, así que separar confirmadas de pendientes es trabajo del frontend.',
            type: 'boolean',
            example: true,
        ),
        new OA\Property(
            property: 'loadedAt',
            description: 'Momento en que el piloto CONFIRMÓ la carga, con el formato propio del proyecto d-m-Y h:i:s A, NO en ISO 8601. null mientras no se confirme. La pone el now() del SERVIDOR y no se acepta desde ningún cuerpo. SE ESCRIBE UNA SOLA VEZ Y NO SE PISA: reconfirmar responde 200 devolviendo esta misma fecha original, sin escribir nada. Y no sirve para ordenar el listado —es nullable—, que va por id ASC.',
            type: 'string',
            nullable: true,
            example: '10-09-2026 07:42:18 AM',
        ),
        new OA\Property(
            property: 'confirmedByName',
            description: 'Nombre del piloto que confirmó la carga, resuelto desde la relación. null mientras no se confirme, y se escribe SIEMPRE junto a loadedAt, nunca una sin la otra. Sale solo el nombre, sin id: hoy coincide con el pilotId del viaje, pero se guarda aparte porque SPEC 24 permite reasignar la tripulación mientras el viaje siga pending.',
            type: 'string',
            nullable: true,
            example: 'Juan Pérez',
        ),
        new OA\Property(
            property: 'registeredByName',
            description: 'Nombre del usuario de la empresa transportista que registró la carga, resuelto desde la relación. En la PRIMERA carga de un viaje es quien lo asignó, porque la crea PATCH /api/trips/{trip}/assignment dentro de su propia transacción. Sale solo el nombre, sin id, y no se envía en el cuerpo: sale siempre del usuario autenticado. No trae el nombre de la empresa: para eso están los endpoints de Carriers.',
            type: 'string',
            nullable: true,
            example: 'María López',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'TripFuelListResponse',
    title: 'Cargas de combustible sin paginar',
    description: <<<'TEXT'
    Respuesta de GET /api/trips/{trip}/fuels cuando NO se envía limit, o cuando el limit no es numérico: se devuelven TODAS las cargas del viaje y el sobre NO incluye total, currentPage ni lastPage.

    totalGallons SÍ aparece en esta forma, aunque no haya paginación: es dato de negocio y no metadata del paginador, con el precedente literal de totalAmount en SPEC 14.

    El orden es FIJO id ASCENDENTE —la cronología real de registro— y NO es configurable: no hay sortBy ni order. ATENCIÓN — es AL REVÉS de GET /api/trips/{trip}/positions, que ordena por recorded_at, y aquí no se puede ordenar por fecha porque loadedAt es nullable.

    NO HAY NI UN SOLO FILTRO: no existe fuelType, ni isConfirmed, ni dateFrom, ni dateTo, ni pilotId. Cualquier query param que no sea limit o page se ignora. Devuelve las CONFIRMADAS Y LAS PENDIENTES mezcladas, y distinguirlas es trabajo del frontend con la clave isConfirmed.

    Un viaje sin ninguna carga —los asignados antes de SPEC 27— devuelve 200 con data vacío y totalGallons "0.00", NUNCA 404.
    TEXT,
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Cargas de combustible obtenidas correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/TripFuel')),
        new OA\Property(
            property: 'totalGallons',
            description: 'SUMA de los galones de las cargas CONFIRMADAS del viaje, como CADENA con dos decimales. ATENCIÓN — SOLO LAS CONFIRMADAS: una carga registrada y no confirmada NO suma, así que un viaje recién asignado devuelve "0.00" teniendo ya una carga en data con isConfirmed en false. El cero es explicable, no un error. NO CONFUNDIR CON total: total es el CONTEO de cargas que aporta el paginador y solo aparece al paginar; totalGallons es una CANTIDAD DE COMBUSTIBLE y aparece SIEMPRE, con y sin limit. Se calcula sobre la consulta clonada y ANTES de paginar, así que con ?limit=10 sobre un viaje de 25 cargas sigue siendo el total del viaje y no el de la página. Es el MISMO número que el totalFuelGallons del detalle del viaje. No hay desglose por tipo de combustible ni un segundo total que incluya las pendientes.',
            type: 'string',
            example: '145.50',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedTripFuelListResponse',
    title: 'Cargas de combustible paginadas',
    description: <<<'TEXT'
    Respuesta de GET /api/trips/{trip}/fuels cuando se envía un limit numérico: los metadatos de paginación salen APLANADOS EN LA RAÍZ del sobre, junto a statusCode, message, data y totalGallons, NO anidados bajo meta.

    ATENCIÓN — en esta forma conviven en la misma raíz totalGallons y total, y NO significan lo mismo: total es el CONTEO de cargas del viaje (un entero) y totalGallons es la SUMA en galones de las CONFIRMADAS (una cadena con dos decimales). Un viaje con tres cargas de 45.50 sin confirmar trae total en 3 y totalGallons en "0.00".

    ATENCIÓN — EL TAMAÑO DE PÁGINA SE ACOTA A [10, 100], a diferencia de GET /api/trips, que no tiene piso de 10 y respeta el limit tal cual. Aquí limit=1 y limit=5 devuelven páginas de 10, y limit=500 devuelve páginas de 100.

    El orden sigue siendo id ASCENDENTE, así que page=1 son las cargas más antiguas del viaje y la última página, las más recientes.
    TEXT,
    allOf: [
        new OA\Schema(ref: '#/components/schemas/TripFuelListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class TripFuelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Eight keys in camelCase. `isConfirmed` is **derived** from `loaded_at` and has no
     * column of its own, the same way `currentValue` is derived in SPEC 17: the two
     * states of a load are «unconfirmed» and «confirmed», and a `status` column could
     * only end up contradicting the date.
     *
     * `gallons` leaves as a two decimal string —the model deliberately does not cast
     * it—, like `price` in SPEC 17 and `salary` in SPEC 11, and `fuelType` leaves with
     * the **raw enum value in English**, untranslated, like `LocationType` in SPEC 21
     * and `TripStatus` in SPEC 24.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Sí viaja, a diferencia del rastro de SPEC 26: la confirmación se pide por /api/trip-fuels/{tripFuel}, fuera del viaje. */
            'tripId' => $this->trip_id,
            'gallons' => number_format((float) $this->gallons, 2, '.', ''),
            'fuelType' => $this->fuel_type?->value,
            /** Derivado, sin columna: null en `loaded_at` es «sin confirmar» y nada más. */
            'isConfirmed' => $this->loaded_at !== null,
            /** El formato de fecha del resto del dominio, nunca ISO 8601. */
            'loadedAt' => $this->loaded_at?->format('d-m-Y h:i:s A'),
            'confirmedByName' => $this->confirmedBy?->name,
            'registeredByName' => $this->registeredBy?->name,
        ];
    }
}
