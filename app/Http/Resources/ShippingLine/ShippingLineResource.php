<?php

namespace App\Http\Resources\ShippingLine;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ShippingLine',
    title: 'Naviera',
    description: <<<'TEXT'
    Naviera del catálogo nacional: un nombre, y nada más. Es el dominio MÁS PEQUEÑO del proyecto —UN SOLO CAMPO DE NEGOCIO, por debajo incluso de los dos que tiene un Cliente— y NO pertenece a ninguna empresa transportista, por eso el recurso no expone carrierId ni carrierName y NINGUNA ruta del dominio lleva el middleware carrier.required. Su lectura está abierta a los cuatro roles: administrator, carrier, pilot y manager, incluido un carrier que todavía no ha registrado su empresa; la escritura es exclusiva del administrator.

    ATENCIÓN — ESTE CATÁLOGO BORRA DE VERDAD, Y ES SU DIFERENCIA MÁS IMPORTANTE FRENTE A Products, Locations, Zones y Departure Points. En esos cuatro el DELETE es una baja lógica sobre un status booleano: la fila se queda, se sigue listando y se puede reactivar. Aquí el DELETE es un soft delete de verdad: la fila DESAPARECE del listado y del detalle, NO existe ningún filtro que la devuelva, NO hay endpoint /restore y NO HAY FORMA DE RECUPERARLA POR LA API. Una naviera borrada por error solo se recupera desde la base de datos. Por eso este recurso no tiene status y sí tiene deletedAt.

    ATENCIÓN — EL BORRADO NO LIBERA EL name. El índice único sigue ocupado por la fila borrada, así que dar de alta una naviera con el nombre de una eliminada responde 400 —«Ya existe una naviera con ese nombre, que puede haber sido eliminada»— aunque esa naviera sea invisible en todos los endpoints. El «que puede haber sido eliminada» del mensaje está puesto justamente para explicar un choque contra una fila que el llamante no puede ver por ninguna vía. AQUÍ DUELE MÁS QUE EN Clients: allí quedaba un segundo identificador al que recurrir, aquí el nombre es lo único que hay.

    Solo hay UN campo de negocio: name. No hay sigla, ni contacto, ni teléfono, ni correo, ni país, ni web, ni notas, ni logo, ni status. NADA CUELGA TODAVÍA DE UNA NAVIERA: ninguna tabla del proyecto tiene shipping_line_id, no hay navieras por destino ni tarifas por naviera, y tampoco está relacionada con los puertos —los destinos de tipo port de Locations—, así que borrar una no rompe ninguna otra entidad.

    ATENCIÓN — createdAt, updatedAt y deletedAt NO viajan en ISO 8601, sino con el formato propio d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM), igual que en Clients, Locations, Departure Points, Products y Zones. Por eso se documentan como string SIN format date-time: parsearlos como ISO falla.

    El name se devuelve SIEMPRE normalizado y EN MAYÚSCULAS. Son exactamente SEIS claves, en camelCase y en este orden: id, name, registeredByName, createdAt, updatedAt y deletedAt.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico de la fila (shipping_lines.id). Es el valor que va en el parámetro {shippingLine} de las rutas de detalle, actualización y baja. Sobrevive a la edición del nombre: corregirlo conserva el id. ATENCIÓN — sobrevive también al DELETE, porque la fila sigue en la base con su deleted_at puesto, pero ese id ya no es alcanzable: GET /api/shipping-lines/{shippingLine} responde 404 y PATCH o DELETE responden 400.',
            type: 'integer',
            example: 7,
        ),
        new OA\Property(
            property: 'name',
            description: 'Nombre de la naviera, SIEMPRE EN MAYÚSCULAS y de 255 caracteres como máximo. ES EL ÚNICO CAMPO DE NEGOCIO DEL DOMINIO y también su único identificador legible: no hay ningún otro dato que distinga dos navieras. Se normaliza al crear y al actualizar: se recortan los extremos, se colapsan los espacios internos a uno y se pasa a mayúsculas, así que enviar "  maersk   line  " guarda y devuelve "MAERSK LINE". Es único, y esa unicidad es GLOBAL Y PERMANENTE: incluye a las navieras borradas, que lo siguen ocupando para siempre. Al estar siempre en mayúsculas, la comparación resulta insensible a mayúsculas sin depender del collation del motor. Es también el campo —el único— que barre el filtro search del listado.',
            type: 'string',
            maxLength: 255,
            example: 'MAERSK LINE',
        ),
        new OA\Property(
            property: 'registeredByName',
            description: 'Nombre del administrador que dio de alta la naviera, obtenido de la relación registeredBy. NO se envía en el cuerpo: sale siempre del usuario autenticado, y mandarlo en el JSON se descarta sin error. El PATCH NO lo reescribe, así que sigue apuntando a quien creó la fila aunque la haya editado otro administrador. No hay campo con el autor de la última edición ni bitácora de cambios: lo más cercano a una auditoría es updatedAt.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'createdAt',
            description: 'Fecha de alta de la naviera. ATENCIÓN — NO viaja en ISO 8601, sino con el formato propio d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM), igual que en Clients, Locations, Departure Points, Products y Zones. Por eso se documenta como string sin format date-time: un cliente generado desde este schema que intentara parsearlo como ISO fallaría. Está pensado para mostrarse tal cual y no cambia nunca.',
            type: 'string',
            nullable: true,
            example: '26-08-2026 08:45:12 PM',
        ),
        new OA\Property(
            property: 'updatedAt',
            description: 'Fecha del último cambio del nombre; es lo más cercano a una auditoría que ofrece el dominio, porque no se guarda ningún valor anterior ni quién lo cambió. Mismo formato propio d-m-Y h:i:s A que createdAt, tampoco ISO 8601. Un PATCH con el cuerpo vacío es un no-op y no lo mueve.',
            type: 'string',
            nullable: true,
            example: '26-08-2026 08:51:40 PM',
        ),
        new OA\Property(
            property: 'deletedAt',
            description: 'Fecha del borrado lógico de la naviera. ATENCIÓN — ES null EN CUATRO DE LOS CINCO ENDPOINTS: en el listado, en el detalle, en el alta y en la actualización, porque ninguno de ellos puede alcanzar una naviera borrada. LA ÚNICA RESPUESTA DE LA API QUE LO DEVUELVE CON VALOR ES LA DEL PROPIO DELETE, que pinta la fila que se acaba de borrar. No sirve, por tanto, para descubrir navieras eliminadas: no existe ningún parámetro que las liste. Mismo formato propio d-m-Y h:i:s A que las otras dos fechas. Su presencia en el recurso documenta hacia el front que este catálogo —al contrario que los de status booleano— borra de verdad.',
            type: 'string',
            nullable: true,
            example: '26-08-2026 09:14:03 PM',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ShippingLineListResponse',
    title: 'Listado de navieras sin paginar',
    description: 'Respuesta de GET /api/shipping-lines cuando no se envía limit o cuando el limit no es numérico: se devuelven todas las navieras que pasen el filtro search y el sobre NO incluye total, currentPage ni lastPage. Es la forma que quiere un selector de naviera que necesita la lista entera de una vez. Las navieras borradas NUNCA aparecen y no hay ningún filtro que las muestre. El orden es siempre id ASC.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Navieras obtenidas correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/ShippingLine')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedShippingLineListResponse',
    title: 'Listado de navieras paginado',
    description: 'Respuesta de GET /api/shipping-lines cuando se envía un limit numérico: los metadatos de paginación salen APLANADOS en la raíz del sobre, junto a statusCode, message y data, no anidados bajo meta. El total cuenta solo las navieras vivas: las borradas no entran en la cuenta.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/ShippingLineListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class ShippingLineResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `deletedAt` is null on four of the five endpoints —index, show, store and
     * update— because none of them can reach a deleted shipping line. The exception is
     * the response of the DELETE itself, which paints the row that was just soft
     * deleted and therefore carries the deletion timestamp, in the same `d-m-Y h:i:s A`
     * format as the other two dates. That is the only way a caller ever sees this key
     * with a value, and it documents towards the front end that this catalog —unlike
     * the six with a boolean status— deletes for real.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
            'deletedAt' => $this->deleted_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
