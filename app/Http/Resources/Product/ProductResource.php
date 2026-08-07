<?php

namespace App\Http\Resources\Product;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Product',
    title: 'Producto',
    description: <<<'TEXT'
    Producto del catálogo nacional: la mercancía que se transporta. NO pertenece a ninguna empresa transportista, por eso el recurso no expone carrierId ni carrierName y su lectura está abierta a los cuatro roles —administrator, carrier, pilot y manager—, incluido un carrier que todavía no ha registrado su empresa.

    El catálogo es deliberadamente mínimo: solo name y status. No hay categoría, unidad de medida, precio, peso por caja, código SKU ni imagen, y el producto no está relacionado todavía con vehículos, viajes ni guías.

    ATENCIÓN — el name que se devuelve NO es necesariamente el que se envió: siempre viaja normalizado (recortado, con los espacios internos colapsados y EN MAYÚSCULAS). Un cliente que quiera reflejar lo que acaba de guardar debe pintar lo que devuelve la respuesta, no lo que tecleó el usuario.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico de la fila (products.id). Es el valor que va en el parámetro {product} de las rutas de detalle, actualización, toggle y baja.',
            type: 'integer',
            example: 1,
        ),
        new OA\Property(
            property: 'name',
            description: 'Nombre del producto, SIEMPRE EN MAYÚSCULAS. Se normaliza al crear y al actualizar: se recortan los extremos, se colapsan los espacios internos a uno y se pasa a mayúsculas, así que enviar "  mini   zanahoria  " guarda y devuelve "MINI ZANAHORIA". Es único a nivel global, y al estar siempre en mayúsculas la unicidad resulta insensible a mayúsculas sin depender del collation del motor.',
            type: 'string',
            example: 'BROCOLI',
        ),
        new OA\Property(
            property: 'status',
            description: 'Disponibilidad del producto, como BOOLEANO JSON (true o false), nunca como 1/0 ni como cadena. Nace en true y el cuerpo del alta no puede fijarlo. Pasa a false con DELETE /api/products/{product} —que es baja lógica, no borrado— o con PATCH y status: false, y vuelve a true con PATCH /api/products/{product}/toggle-status o con PATCH y status: true. Un producto inactivo sigue existiendo, sigue apareciendo en GET /api/products sin filtros y sigue siendo consultable por id.',
            type: 'boolean',
            example: true,
        ),
        new OA\Property(
            property: 'registeredByName',
            description: 'Nombre del administrador que dio de alta el producto, obtenido de la relación registeredBy. No se envía en el cuerpo: sale del usuario autenticado. El PATCH no lo reescribe, así que sigue apuntando a quien creó la fila aunque la haya editado otro administrador.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'createdAt',
            description: 'Fecha de alta del producto. ATENCIÓN — excepción deliberada de este dominio: NO viaja en ISO 8601 como en el resto del proyecto, sino con el formato d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM). Por eso se documenta como string sin format date-time: un cliente generado desde este schema que intentara parsearlo como ISO fallaría. Está pensado para mostrarse tal cual.',
            type: 'string',
            nullable: true,
            example: '07-08-2026 06:03:22 PM',
        ),
        new OA\Property(
            property: 'updatedAt',
            description: 'Fecha del último cambio de name o de status; es lo más cercano a una auditoría que ofrece el dominio, porque no se guarda el valor anterior de ninguno de los dos. Mismo formato propio d-m-Y h:i:s A que createdAt, tampoco ISO 8601.',
            type: 'string',
            nullable: true,
            example: '07-08-2026 06:11:40 PM',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ProductListResponse',
    title: 'Listado de productos sin paginar',
    description: 'Respuesta de GET /api/products cuando no se envía limit o cuando el limit no es numérico: se devuelven todos los productos que pasen los filtros y el sobre NO incluye total, currentPage ni lastPage. Es la forma que quiere un selector que necesita el catálogo entero. Sin el filtro status, la lista mezcla activos e inactivos.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Productos obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Product')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedProductListResponse',
    title: 'Listado de productos paginado',
    description: 'Respuesta de GET /api/products cuando se envía un limit numérico: los metadatos de paginación salen APLANADOS en la raíz del sobre, junto a statusCode, message y data, no anidados bajo meta.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/ProductListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class ProductResource extends JsonResource
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
            'name' => $this->name,
            'status' => $this->status,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
