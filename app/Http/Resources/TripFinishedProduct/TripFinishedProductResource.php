<?php

namespace App\Http\Resources\TripFinishedProduct;

use App\Models\TripFinishedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * @mixin TripFinishedProduct
 */
#[OA\Schema(
    schema: 'TripFinishedProduct',
    title: 'Línea de producto terminado de un viaje',
    description: 'Cuántas cajas de un producto terminado lleva un viaje. DIEZ CLAVES en camelCase. code, name, presentation y boxesPerPallet se leen EN VIVO del producto terminado —también si fue borrado después—, sin copia: editar el producto cambia lo que muestran los viajes que lo llevan. createdAt usa el formato d-m-Y h:i:s A, no ISO 8601.',
    properties: [
        new OA\Property(property: 'id', description: 'Id de la línea: el parámetro {tripFinishedProduct} del PATCH y del DELETE.', type: 'integer', example: 1),
        new OA\Property(property: 'tripId', description: 'Id del viaje. Inmutable.', type: 'integer', example: 12),
        new OA\Property(property: 'finishedProductId', description: 'Id del producto terminado. Inmutable.', type: 'integer', example: 4),
        new OA\Property(property: 'code', description: 'Código del producto terminado, en vivo.', type: 'string', example: 'BRO-IQF-10'),
        new OA\Property(property: 'name', description: 'Nombre del producto terminado, en vivo.', type: 'string', example: 'BRÓCOLI FLORETE IQF'),
        new OA\Property(property: 'presentation', description: 'Presentación del producto, STRING de dos decimales, en vivo.', type: 'string', example: '10.00'),
        new OA\Property(property: 'boxesPerPallet', description: 'Cajas por tarima del producto, STRING de dos decimales, en vivo. La API no calcula tarimas.', type: 'string', example: '96.50'),
        new OA\Property(property: 'boxes', description: 'Cajas de la línea, ENTERO. El único campo editable.', type: 'integer', example: 960),
        new OA\Property(property: 'registeredByName', description: 'Nombre de quien registró la línea. No cambia al editarla.', type: 'string', nullable: true, example: 'Admin'),
        new OA\Property(property: 'createdAt', description: 'Fecha de alta de la línea, formato d-m-Y h:i:s A.', type: 'string', example: '25-09-2026 10:15:00 AM'),
    ],
    type: 'object',
)]
class TripFinishedProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Ten keys in camelCase. `code`, `name`, `presentation` and `boxesPerPallet` are read
     * **live** from the finished product —trashed included—, never copied into the line:
     * editing the SKU changes what every trip carrying it shows. The two decimals leave
     * as two decimal strings, like in `FinishedProductResource`; `boxes` as an integer.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tripId' => $this->trip_id,
            'finishedProductId' => $this->finished_product_id,
            'code' => $this->finishedProduct?->code,
            'name' => $this->finishedProduct?->name,
            'presentation' => $this->finishedProduct === null ? null : number_format((float) $this->finishedProduct->presentation, 2, '.', ''),
            'boxesPerPallet' => $this->finishedProduct === null ? null : number_format((float) $this->finishedProduct->boxes_per_pallet, 2, '.', ''),
            'boxes' => $this->boxes,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
