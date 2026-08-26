<?php

namespace App\Http\Resources\Client;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Client',
    title: 'Cliente',
    description: <<<'TEXT'
    Cliente del catálogo nacional: un código y un nombre, y nada más. Es el dominio MÁS PEQUEÑO del proyecto —solo dos campos de negocio— y NO pertenece a ninguna empresa transportista, por eso el recurso no expone carrierId ni carrierName y NINGUNA ruta del dominio lleva el middleware carrier.required. Su lectura está abierta a los cuatro roles: administrator, carrier, pilot y manager, incluido un carrier que todavía no ha registrado su empresa; la escritura es exclusiva del administrator.

    ATENCIÓN — ESTE CATÁLOGO BORRA DE VERDAD, Y ES SU DIFERENCIA MÁS IMPORTANTE FRENTE A Products, Locations, Zones y Departure Points. En esos cuatro el DELETE es una baja lógica sobre un status booleano: la fila se queda, se sigue listando y se puede reactivar. Aquí el DELETE es un soft delete de verdad: la fila DESAPARECE del listado y del detalle, NO existe ningún filtro que la devuelva, NO hay endpoint /restore y NO HAY FORMA DE RECUPERARLA POR LA API. Un cliente borrado por error solo se recupera desde la base de datos. Por eso este recurso no tiene status y sí tiene deletedAt.

    ATENCIÓN — EL BORRADO NO LIBERA NI EL code NI EL name. Los dos índices únicos siguen ocupados por la fila borrada, así que dar de alta un cliente con el código o el nombre de uno eliminado responde 400 —«Ya existe un cliente con ese código, que puede haber sido eliminado»— aunque ese cliente sea invisible en todos los endpoints. El «que puede haber sido eliminado» del mensaje está puesto justamente para explicar un choque contra una fila que el llamante no puede ver por ninguna vía.

    Solo hay DOS campos de negocio: code y name. No hay dirección, ni NIT, ni teléfono, ni contacto, ni status. NADA CUELGA TODAVÍA DE UN CLIENTE: ninguna tabla del proyecto tiene client_id, así que borrar uno no rompe ninguna otra entidad y el cliente no participa en tarifas, cotizaciones ni viajes.

    ATENCIÓN — createdAt, updatedAt y deletedAt NO viajan en ISO 8601, sino con el formato propio d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM), igual que en Locations, Departure Points, Products y Zones. Por eso se documentan como string SIN format date-time: parsearlos como ISO falla.

    El code y el name se devuelven SIEMPRE normalizados y EN MAYÚSCULAS. Son exactamente SIETE claves, en camelCase y en este orden: id, code, name, registeredByName, createdAt, updatedAt y deletedAt.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico de la fila (clients.id). Es el valor que va en el parámetro {client} de las rutas de detalle, actualización y baja. Sobrevive a la edición del código y del nombre: corregir cualquiera de los dos conserva el id. ATENCIÓN — sobrevive también al DELETE, porque la fila sigue en la base con su deleted_at puesto, pero ese id ya no es alcanzable: GET /api/clients/{client} responde 404 y PATCH o DELETE responden 400.',
            type: 'integer',
            example: 7,
        ),
        new OA\Property(
            property: 'code',
            description: 'Código del cliente, SIEMPRE EN MAYÚSCULAS y de 15 caracteres como máximo. Lo teclea el administrador —no se genera solo, al contrario que el code de un Carrier— y se normaliza al crear y al actualizar recortando los extremos y pasando a mayúsculas. ATENCIÓN — NUNCA CONTIENE ESPACIOS: un código con cualquier espacio interior se rechaza con 422 en vez de colapsarlo, para que un error de captura se vea en lugar de convertirse en otro código. Es único, y esa unicidad es GLOBAL Y PERMANENTE: incluye a los clientes borrados, que lo siguen ocupando para siempre. Es también uno de los dos campos que barre el filtro search del listado.',
            type: 'string',
            maxLength: 15,
            example: 'CLI-001',
        ),
        new OA\Property(
            property: 'name',
            description: 'Razón social del cliente, SIEMPRE EN MAYÚSCULAS. Se normaliza al crear y al actualizar: se recortan los extremos, se colapsan los espacios internos a uno y se pasa a mayúsculas, así que enviar "  agro   del sur  " guarda y devuelve "AGRO DEL SUR". A diferencia del code, aquí los espacios interiores SÍ se colapsan en silencio: en un nombre son un descuido de tecleo, no un error de captura. Es único, con la misma unicidad global y permanente que el code —un cliente borrado lo sigue reservando— y al estar siempre en mayúsculas la comparación resulta insensible a mayúsculas sin depender del collation del motor. Es el otro campo que barre el filtro search.',
            type: 'string',
            maxLength: 255,
            example: 'AGROEXPORTADORA DEL SUR',
        ),
        new OA\Property(
            property: 'registeredByName',
            description: 'Nombre del administrador que dio de alta el cliente, obtenido de la relación registeredBy. NO se envía en el cuerpo: sale siempre del usuario autenticado, y mandarlo en el JSON se descarta sin error. El PATCH NO lo reescribe, así que sigue apuntando a quien creó la fila aunque la haya editado otro administrador. No hay campo con el autor de la última edición ni bitácora de cambios: lo más cercano a una auditoría es updatedAt.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'createdAt',
            description: 'Fecha de alta del cliente. ATENCIÓN — NO viaja en ISO 8601, sino con el formato propio d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM), igual que en Locations, Departure Points, Products y Zones. Por eso se documenta como string sin format date-time: un cliente generado desde este schema que intentara parsearlo como ISO fallaría. Está pensado para mostrarse tal cual y no cambia nunca.',
            type: 'string',
            nullable: true,
            example: '26-08-2026 08:45:12 PM',
        ),
        new OA\Property(
            property: 'updatedAt',
            description: 'Fecha del último cambio del código o del nombre; es lo más cercano a una auditoría que ofrece el dominio, porque no se guarda ningún valor anterior ni quién lo cambió. Mismo formato propio d-m-Y h:i:s A que createdAt, tampoco ISO 8601. Un PATCH con el cuerpo vacío es un no-op y no lo mueve.',
            type: 'string',
            nullable: true,
            example: '26-08-2026 08:51:40 PM',
        ),
        new OA\Property(
            property: 'deletedAt',
            description: 'Fecha del borrado lógico del cliente. ATENCIÓN — ES null EN CUATRO DE LOS CINCO ENDPOINTS: en el listado, en el detalle, en el alta y en la actualización, porque ninguno de ellos puede alcanzar un cliente borrado. LA ÚNICA RESPUESTA DE LA API QUE LO DEVUELVE CON VALOR ES LA DEL PROPIO DELETE, que pinta la fila que se acaba de borrar. No sirve, por tanto, para descubrir clientes eliminados: no existe ningún parámetro que los liste. Mismo formato propio d-m-Y h:i:s A que las otras dos fechas. Su presencia en el recurso documenta hacia el front que este catálogo —al contrario que los otros seis— borra de verdad.',
            type: 'string',
            nullable: true,
            example: '26-08-2026 09:14:03 PM',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ClientListResponse',
    title: 'Listado de clientes sin paginar',
    description: 'Respuesta de GET /api/clients cuando no se envía limit o cuando el limit no es numérico: se devuelven todos los clientes que pasen el filtro search y el sobre NO incluye total, currentPage ni lastPage. Es la forma que quiere un selector de cliente que necesita la lista entera de una vez. Los clientes borrados NUNCA aparecen y no hay ningún filtro que los muestre. El orden es siempre id ASC.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Clientes obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Client')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedClientListResponse',
    title: 'Listado de clientes paginado',
    description: 'Respuesta de GET /api/clients cuando se envía un limit numérico: los metadatos de paginación salen APLANADOS en la raíz del sobre, junto a statusCode, message y data, no anidados bajo meta. El total cuenta solo los clientes vivos: los borrados no entran en la cuenta.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/ClientListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class ClientResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `deletedAt` is null on four of the five endpoints —index, show, store and
     * update— because none of them can reach a deleted client. The exception is the
     * response of the DELETE itself, which paints the row that was just soft deleted
     * and therefore carries the deletion timestamp, in the same `d-m-Y h:i:s A`
     * format as the other two dates. That is the only way a caller ever sees this key
     * with a value, and it documents towards the front end that this catalog —unlike
     * the other six— deletes for real.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
            'deletedAt' => $this->deleted_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
