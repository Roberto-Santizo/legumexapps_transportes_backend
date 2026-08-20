<?php

namespace App\Http\Resources\Accessory;

use App\Models\Accessory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Accessory',
    title: 'Accesorio',
    description: <<<'TEXT'
    Accesorio del inventario nacional: una llanta, un gato hidráulico, un juego de cadenas. NO pertenece a ninguna empresa transportista ni está asignado a ningún vehículo —es un dato de Legumex, común a todos—, por eso el recurso no expone carrierId ni vehicleId y NINGUNA ruta del dominio lleva el middleware carrier.required. Su lectura está abierta a los cuatro roles: administrator, carrier, pilot y manager, incluido un carrier que todavía no ha registrado su empresa.

    ATENCIÓN — UNA FILA ES UNA UNIDAD FÍSICA: no existe el campo quantity. Dos llantas iguales son DOS accesorios con DOS códigos distintos, no una fila con cantidad 2. Quien necesite saber "cuántas llantas hay" cuenta filas, no lee un contador.

    ATENCIÓN — currentValue ES UN CAMPO DERIVADO Y DE SOLO SALIDA: no tiene columna en la base, no lo recalcula ningún job y no está cacheado; se calcula en cada lectura, tanto en el listado como en el detalle. El mismo accesorio, leído hoy y dentro de un mes, devuelve dos valores distintos sin que nadie haya escrito en la tabla: NO es un bug. No se puede filtrar ni ordenar por él, y mandarlo en el cuerpo de un alta o de un PATCH se ignora en silencio.

    ATENCIÓN — price, annualDepreciation y currentValue viajan como CADENAS de DOS DECIMALES ("1250.00"), no como números JSON: son casts decimal:2 entregados tal cual, para que ningún float pierda dígitos. Hay que parsearlos en el cliente antes de operar con ellos. La moneda es GTQ por convención: ningún campo la nombra.

    ATENCIÓN — las dos fechas usan formatos propios y distintos, y ninguno es ISO 8601: purchaseDate es d-m-Y (el día de la compra, sin hora) y createdAt es d-m-Y h:i:s A (cuándo se capturó la fila, con hora de 12 horas y AM/PM). Son dos cosas diferentes: un accesorio comprado hace tres años puede haberse capturado ayer. Por eso ambos se documentan como string SIN format date-time: parsearlos como ISO falla. El recurso NO expone updatedAt.

    El name y el code se devuelven SIEMPRE EN MAYÚSCULAS. El status es un ENUM DE TRES VALORES (active, inactive, under_repair), no un booleano, y la baja es LÓGICA: DELETE pone status en "inactive", la fila nunca desaparece y sigue apareciendo en el listado sin filtros.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico de la fila (accessories.id). Es el valor que va en el parámetro {accessory} de las rutas de detalle, actualización y baja, y el que ignoran las reglas de unicidad al editar, para que el accesorio pueda reenviar su propio nombre y su propio código sin chocar consigo mismo. Sobrevive a la baja lógica y a un cambio de code: el código es editable, el id no. No confundirlo con code, que es el identificador que teclea el usuario.',
            type: 'integer',
            example: 7,
        ),
        new OA\Property(
            property: 'name',
            description: 'Nombre del accesorio, SIEMPRE EN MAYÚSCULAS. Se normaliza al crear y al actualizar: se recortan los extremos, se colapsan los espacios internos a uno y se pasa a mayúsculas, así que enviar "  gato   hidráulico  " guarda y devuelve "GATO HIDRÁULICO". Es único a nivel global, y al estar siempre en mayúsculas la unicidad resulta insensible a mayúsculas sin depender del collation del motor. Describe el TIPO de pieza; para distinguir dos unidades iguales está el code, no el name.',
            type: 'string',
            example: 'GATO HIDRÁULICO 3 TONELADAS',
        ),
        new OA\Property(
            property: 'code',
            description: 'Código interno o número de serie de ESTA unidad física, SIEMPRE EN MAYÚSCULAS. Se normaliza distinto que el name: se recorta y se pasa a mayúsculas pero NO se colapsan los espacios internos, porque un código es un identificador y no una frase — "A 100" y "A100" son dos códigos DISTINTOS y ambos pueden coexistir. Es obligatorio y único a nivel GLOBAL SIN MIRAR EL STATUS: un accesorio dado de baja NO libera su código, al contrario que la placa de un vehículo, que un inactive sí libera. Es EDITABLE: corregir un código mal tecleado conserva la fila y su id.',
            type: 'string',
            example: 'ACC-0042',
        ),
        new OA\Property(
            property: 'description',
            description: 'Descripción libre del accesorio: marca, medidas, dónde está guardado. Es el ÚNICO campo del recurso que puede ser null: se omite en el alta o se limpia enviando null explícito en el PATCH —omitir la clave lo deja intacto—. Máximo 1000 caracteres. NO participa en la búsqueda del listado, que solo mira name y code.',
            type: 'string',
            nullable: true,
            example: 'Marca Truper, guardado en bodega central, estante 4',
        ),
        new OA\Property(
            property: 'price',
            description: 'Precio de compra de la unidad, en GTQ. ATENCIÓN — viaja como CADENA de DOS DECIMALES ("1250.00"), no como número: es el cast decimal:2 entregado sin convertir a float. Es el precio HISTÓRICO de adquisición y NO se recalcula nunca: lo que envejece es currentValue, que se deriva de él. Es editable por el PATCH, y editarlo cambia también el currentValue de la siguiente lectura. Siempre es mayor que 0: un accesorio con precio 0 no se puede dar de alta.',
            type: 'string',
            example: '1250.00',
        ),
        new OA\Property(
            property: 'purchaseDate',
            description: 'Día en que se COMPRÓ el accesorio, en formato d-m-Y y SIN HORA — NO es ISO 8601, así que parsearlo como ISO falla. Es el origen del cálculo de la antigüedad y por tanto del currentValue: moverla hacia atrás deprecia más y moverla hacia adelante deprecia menos. NO confundirla con createdAt, que es cuándo se capturó la fila: un accesorio comprado hace tres años puede haberse dado de alta ayer. Nunca es futura.',
            type: 'string',
            example: '15-03-2024',
        ),
        new OA\Property(
            property: 'annualDepreciation',
            description: 'Porcentaje ANUAL de depreciación LINEAL de este accesorio, en [0, 100]. ATENCIÓN — viaja como CADENA de DOS DECIMALES ("20.00"), no como número, y es un PORCENTAJE, no una fracción: 20.00 significa que pierde el 20 % de su precio de compra cada año. Con 0.00 el accesorio no se deprecia nunca y su currentValue es igual a price para siempre; con 100.00 llega a 0.00 al cumplir el año y ahí se queda. Es editable, y editarlo cambia el currentValue de la siguiente lectura sin tocar el price.',
            type: 'string',
            example: '20.00',
        ),
        new OA\Property(
            property: 'currentValue',
            description: <<<'VALUE'
            Valor DEPRECIADO del accesorio a día de hoy, en GTQ y como CADENA de dos decimales. ATENCIÓN — ES UN CAMPO DERIVADO Y DE SOLO SALIDA: no existe como columna, no lo escribe ningún job, no está cacheado y NO SE PUEDE ENVIAR — mandarlo en el cuerpo de un alta o de un PATCH se ignora en silencio. Se recalcula en CADA lectura y sale tanto en el listado como en el detalle.

            Fórmula exacta, depreciación LINEAL y antigüedad en fracción de años por días:

                años         = purchaseDate.diffInDays(hoy) / 365
                depreciado   = price * (annualDepreciation / 100) * años
                currentValue = round(max(0, price - depreciado), 2)

            Los 365 días son fijos, SIN corrección por año bisiesto: la diferencia son horas sobre una cifra que ya es una convención contable. Al contarse por días y no por aniversarios, el valor baja UN POCO CADA DÍA en vez de dar saltos anuales, así que EL MISMO ACCESORIO DEVUELVE UN VALOR DISTINTO CADA DÍA SIN QUE NADIE ESCRIBA EN LA TABLA: no es un bug y no hay que reportarlo. El DÍA DE LA COMPRA currentValue es exactamente igual a price. Tiene PISO EN 0.00 y NUNCA es negativo: un accesorio totalmente depreciado vale 0.00 por muchos años que pasen.

            NO se puede filtrar ni ordenar por él —el listado solo admite status, search, limit y page, y su orden es id ASC y no configurable—: al no estar en la base, la consulta no lo ve. Quien quiera ordenar por valor debe hacerlo en el cliente, sobre la página que ya recibió.
            VALUE,
            type: 'string',
            example: '640.41',
        ),
        new OA\Property(
            property: 'status',
            description: 'Estado del accesorio como ENUM DE TRES VALORES —"active", "inactive" o "under_repair"—, NUNCA un booleano y nunca 1/0: es la diferencia con Products, Zones y Locations, cuyo status sí es booleano. Nace siempre en "active" y el cuerpo del alta NO puede fijarlo (mandarlo se ignora). El PATCH lo mueve LIBREMENTE entre los tres valores, SIN reglas de transición: de "inactive" se puede saltar a "under_repair" sin pasar por "active". DELETE /api/accessories/{accessory} lo pone en "inactive" —baja LÓGICA, no borrado— y el PATCH con status "active" lo reactiva. Un accesorio inactivo sigue existiendo, sigue siendo consultable por id y SIGUE APARECIENDO en GET /api/accessories sin filtros; además conserva su código ocupado para siempre.',
            type: 'string',
            enum: ['active', 'inactive', 'under_repair'],
            example: 'active',
        ),
        new OA\Property(
            property: 'registeredBy',
            description: 'ATENCIÓN — es el NOMBRE del administrador que dio de alta el accesorio, NO su id: la pantalla lo imprime y nadie navega a ese usuario. Sale de la relación registeredBy y no se envía en el cuerpo: se toma del usuario autenticado. El PATCH NO lo reescribe, así que sigue apuntando a quien creó la fila aunque la haya editado otro administrador. Es null solo si el usuario que la registró ya no se puede resolver.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'createdAt',
            description: 'Fecha en la que se CAPTURÓ la fila, con el formato propio d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM), NO ISO 8601 — por eso se documenta como string sin format date-time: un cliente generado desde este schema que intentara parsearlo como ISO fallaría. NO es la fecha de compra: para eso está purchaseDate, que es la que deprecia. El recurso NO expone updatedAt, así que por la API no hay forma de saber cuándo se editó el accesorio por última vez.',
            type: 'string',
            nullable: true,
            example: '20-08-2026 08:45:12 PM',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'AccessoryListResponse',
    title: 'Listado de accesorios sin paginar',
    description: 'Respuesta de GET /api/accessories cuando no se envía limit o cuando el limit no es numérico: se devuelven todos los accesorios que pasen los filtros y el sobre NO incluye total, currentPage ni lastPage. Sin el filtro status, la lista mezcla los tres estados —incluidos los dados de baja—, siempre ordenada por id ASC. Cada elemento trae su currentValue ya calculado para el día de hoy.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Accesorios obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Accessory')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedAccessoryListResponse',
    title: 'Listado de accesorios paginado',
    description: 'Respuesta de GET /api/accessories cuando se envía un limit numérico: los metadatos de paginación —total, currentPage y lastPage— salen APLANADOS en la raíz del sobre, junto a statusCode, message y data, NO anidados bajo meta.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/AccessoryListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
/**
 * @mixin Accessory
 */
class AccessoryResource extends JsonResource
{
    /**
     * Days counted as a year when ageing an accessory.
     *
     * Fixed at 365, with no leap year correction: the difference is hours on a figure
     * that is already an accounting convention.
     */
    private const DAYS_PER_YEAR = 365;

    /**
     * Transform the resource into an array.
     *
     * `registeredBy` is the name of the user that captured the accessory, not its id:
     * the screen prints it and nobody navigates to that user.
     *
     * `purchaseDate` is the day the accessory was bought and carries no time, while
     * `createdAt` is when it was captured — two different things.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            /** Price in GTQ, always with two decimals. */
            'price' => $this->price,
            'purchaseDate' => $this->purchase_date?->format('d-m-Y'),
            'annualDepreciation' => $this->annual_depreciation,
            'currentValue' => $this->currentValue(),
            'status' => $this->status?->value,
            'registeredBy' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
        ];
    }

    /**
     * Derive what the accessory is worth today.
     *
     * Straight-line depreciation, with the age counted as a fraction of years by days
     * so the value moves every day instead of jumping on each anniversary, and a floor
     * at 0.00 so a fully depreciated accessory never comes back negative.
     *
     *     years        = purchase_date->diffInDays(today) / 365
     *     depreciated  = price * (annual_depreciation / 100) * years
     *     currentValue = round(max(0, price - depreciated), 2)
     *
     * This is the only place the formula lives: there is no column backing it, no
     * scheduled job recomputing it and no cache, so the same accessory read today and
     * a month from now returns two different values with nobody writing to the table.
     *
     * It travels as a two decimal string, like `price`: it is money, and this project
     * does not send money as a float.
     */
    private function currentValue(): string
    {
        $price = (float) $this->price;
        $years = $this->purchase_date->diffInDays(Carbon::today()) / self::DAYS_PER_YEAR;
        $depreciated = $price * ((float) $this->annual_depreciation / 100) * $years;

        return number_format(max(0, $price - $depreciated), 2, '.', '');
    }
}
