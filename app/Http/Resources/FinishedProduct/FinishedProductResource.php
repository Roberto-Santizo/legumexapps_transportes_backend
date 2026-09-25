<?php

namespace App\Http\Resources\FinishedProduct;

use App\Models\FinishedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FinishedProduct',
    title: 'Producto terminado',
    description: <<<'TEXT'
    SKU del catálogo nacional de productos terminados: una presentación empacada de un cliente, con su código, su nombre, su presentación y sus cajas por tarima. NO pertenece a ninguna empresa transportista —ninguna ruta lleva carrier.required— y NO TIENE NINGUNA RELACIÓN CON Products (SPEC 07): Product es la mercancía que cotiza en freight-rates; un producto terminado es un SKU de un cliente. Comparten la palabra, no el dominio.

    La lectura está abierta a todos los roles SALVO pilot (administrator, manager, carrier, export, user y shipment); la escritura es solo de administrator y export.

    ATENCIÓN — BORRA DE VERDAD (SoftDeletes, sin /restore): la fila borrada desaparece del listado y del detalle (404) y no hay forma de recuperarla por la API. El borrado NO LIBERA EL code, que sigue ocupado para siempre.

    ATENCIÓN — presentation y boxesPerPallet salen como STRING con dos decimales ("12.50"), no como número. createdAt, updatedAt y deletedAt usan el formato propio d-m-Y h:i:s A, NO ISO 8601.

    Son exactamente ONCE claves, en camelCase y en este orden: id, code, name, presentation, boxesPerPallet, clientId, clientName, registeredByName, createdAt, updatedAt y deletedAt.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico (finished_products.id). Es el valor del parámetro {finishedProduct}. Sobrevive al borrado en la base, pero deja de ser alcanzable: GET responde 404 y PATCH/DELETE 400.',
            type: 'integer',
            example: 12,
        ),
        new OA\Property(
            property: 'code',
            description: 'Código del SKU, SIEMPRE EN MAYÚSCULAS, sin ningún espacio y de 15 caracteres como máximo. Único GLOBAL Y PERMANENTE: incluye a los productos terminados borrados, que lo siguen reservando. Uno de los dos campos que barre el filtro search.',
            type: 'string',
            maxLength: 15,
            example: 'SKU-BRO-001',
        ),
        new OA\Property(
            property: 'name',
            description: 'Nombre del SKU, EN MAYÚSCULAS. A diferencia del resto de catálogos NO se le colapsan los espacios interiores —se guardan tal cual se teclearon— y NO ES ÚNICO: dos productos terminados pueden llamarse igual. Los espacios de los extremos sí los quita el middleware global TrimStrings de Laravel antes de llegar a la validación. Es el otro campo que barre search.',
            type: 'string',
            maxLength: 255,
            example: 'BRÓCOLI  FLORETE IQF',
        ),
        new OA\Property(
            property: 'presentation',
            description: 'Presentación del SKU (tamaño del empaque; la columna no fija unidad). Sale como STRING con exactamente dos decimales, no como número. Siempre entre 0.01 y 99999999.99.',
            type: 'string',
            example: '12.50',
        ),
        new OA\Property(
            property: 'boxesPerPallet',
            description: 'Cajas por tarima. Sale como STRING con exactamente dos decimales, no como número ni entero. Siempre entre 0.01 y 99999999.99.',
            type: 'string',
            example: '80.00',
        ),
        new OA\Property(
            property: 'clientId',
            description: 'Id del cliente (clients.id) dueño del SKU. Obligatorio: todo producto terminado tiene cliente. Puede apuntar a un cliente borrado DESPUÉS del alta: borrar un cliente NO se bloquea por sus productos terminados.',
            type: 'integer',
            example: 7,
        ),
        new OA\Property(
            property: 'clientName',
            description: 'Nombre del cliente. ATENCIÓN — SALE AUNQUE EL CLIENTE ESTÉ BORRADO: la relación lee clientes eliminados, así que un SKU conserva el nombre de su cliente tras borrarlo.',
            type: 'string',
            nullable: true,
            example: 'AGROEXPORTADORA DEL SUR',
        ),
        new OA\Property(
            property: 'registeredByName',
            description: 'Nombre del usuario que dio de alta el SKU. Sale del usuario autenticado, nunca del body, y el PATCH no lo reescribe.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'createdAt',
            description: 'Fecha de alta, formato d-m-Y h:i:s A (NO ISO 8601).',
            type: 'string',
            nullable: true,
            example: '25-09-2026 08:45:12 AM',
        ),
        new OA\Property(
            property: 'updatedAt',
            description: 'Fecha del último cambio, formato d-m-Y h:i:s A (NO ISO 8601).',
            type: 'string',
            nullable: true,
            example: '25-09-2026 08:51:40 AM',
        ),
        new OA\Property(
            property: 'deletedAt',
            description: 'Fecha del borrado lógico. ATENCIÓN — es null en el listado, el detalle, el alta y la actualización; SOLO VIENE CON FECHA EN LA RESPUESTA DEL PROPIO DELETE, que pinta la fila recién borrada. Formato d-m-Y h:i:s A.',
            type: 'string',
            nullable: true,
            example: null,
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'FinishedProductListResponse',
    title: 'Listado de productos terminados sin paginar',
    description: 'Respuesta de GET /api/finished-products sin limit (o con un limit no numérico): todos los productos terminados vivos que pasen los filtros, sin total, currentPage ni lastPage. Los borrados nunca aparecen. Orden id ASC.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Productos terminados obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FinishedProduct')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedFinishedProductListResponse',
    title: 'Listado de productos terminados paginado',
    description: 'Respuesta de GET /api/finished-products con un limit numérico (acotado a [10, 100]): total, currentPage y lastPage salen APLANADOS en la raíz del sobre, no bajo meta. El total cuenta solo los productos terminados vivos que pasen los filtros.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/FinishedProductListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
/**
 * @mixin FinishedProduct
 */
class FinishedProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `clientName` comes through a relation that reads trashed clients, so a SKU keeps
     * its client's name after that client is deleted. `deletedAt` only carries a date
     * in the response of the DELETE itself.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'presentation' => number_format((float) $this->presentation, 2, '.', ''),
            'boxesPerPallet' => number_format((float) $this->boxes_per_pallet, 2, '.', ''),
            'clientId' => $this->client_id,
            'clientName' => $this->client?->name,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
            'deletedAt' => $this->deleted_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
