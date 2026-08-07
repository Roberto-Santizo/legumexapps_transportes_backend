<?php

namespace App\Http\Resources\FuelPrice;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'FuelPrice',
    title: 'Precio de combustible',
    description: <<<'TEXT'
    Precio de combustible del catálogo nacional. NO pertenece a ninguna empresa transportista: es un dato único para toda la aplicación, por eso el recurso no expone carrierId ni carrierName y su lectura está abierta a los cuatro roles.

    Solo puede haber UNA fila con status active por cada fuelType. Registrar un precio nuevo desactiva automáticamente el vigente anterior de ese mismo tipo, así que el resto de filas del tipo son histórico y nunca vuelven a estar vigentes: no existe reactivación por ninguna vía.

    El importe va SIEMPRE en quetzales (GTQ) por galón. Ni la moneda ni la unidad son columnas de la tabla ni viajan en la respuesta: son convención del dominio y solo están documentadas aquí.
    TEXT,
    properties: [
        new OA\Property(property: 'id', type: 'integer', example: 1),
        new OA\Property(
            property: 'fuelType',
            description: 'Tipo de combustible. Los valores viajan en inglés; traducirlos para el usuario final es responsabilidad del cliente. Nunca cambia después del alta: el PATCH no lo acepta.',
            type: 'string',
            enum: ['regular', 'premium', 'diesel', 'diesel_premium'],
            example: 'regular',
        ),
        new OA\Property(
            property: 'price',
            description: 'Precio EN QUETZALES (GTQ) POR GALÓN. Viaja como cadena decimal con dos decimales, por el casting decimal:2 del modelo, no como número: un cliente que lo compare o lo sume debe convertirlo antes. El rango aceptado al escribirlo es [0.01, 999999.99].',
            type: 'string',
            example: '32.45',
        ),
        new OA\Property(
            property: 'status',
            description: 'Estado del precio. Nace siempre en active y solo hay uno así por tipo de combustible: es el que devuelve GET /api/fuel-prices/current. Pasa a inactive cuando se registra un precio nuevo del mismo tipo o cuando se llama a PATCH /api/fuel-prices/{fuelPrice}/deactivate, y a partir de ahí la fila es histórico de solo lectura: cualquier PATCH, DELETE o desactivación sobre ella responde 400.',
            type: 'string',
            enum: ['active', 'inactive'],
            example: 'active',
        ),
        new OA\Property(
            property: 'registeredByName',
            description: 'Nombre del administrador que capturó el precio, obtenido de la relación registeredBy. No se envía en el cuerpo del alta: sale del usuario autenticado.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'createdAt',
            description: 'Fecha de alta del precio y, a la vez, SU FECHA DE VIGENCIA: no existe una columna effective_date porque sería idéntica a esta. Un precio rige desde este instante hasta que otro del mismo tipo lo desplaza. También es la clave de ordenación del listado (created_at DESC, id DESC).',
            type: 'string',
            format: 'date-time',
            nullable: true,
            example: '2026-08-06T18:03:22.000000Z',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'FuelPriceListResponse',
    title: 'Listado de precios de combustible sin paginar',
    description: 'Respuesta de GET /api/fuel-prices cuando no se envía limit o cuando el limit no es numérico: se devuelven todos los registros que pasen los filtros y el sobre no incluye total, currentPage ni lastPage. El listado trae por defecto AMBOS estados, así que mezcla el precio vigente con todo su histórico: para quedarse solo con los vigentes hay que filtrar por status=active.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Precios de combustible obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/FuelPrice')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedFuelPriceListResponse',
    title: 'Listado de precios de combustible paginado',
    description: 'Respuesta de GET /api/fuel-prices cuando se envía un limit numérico: los metadatos de paginación salen aplanados en la raíz del sobre, no anidados bajo meta.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/FuelPriceListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class FuelPriceResource extends JsonResource
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
            'fuelType' => $this->fuel_type->value,
            'price' => $this->price,
            'status' => $this->status->value,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at,
        ];
    }
}
