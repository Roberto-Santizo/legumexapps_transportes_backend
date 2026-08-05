<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use OpenApi\Attributes as OA;

/**
 * Generic pagination envelope, reusable by any resource of the project.
 *
 * Wraps a paginator with the child resource that renders each item:
 * `new PaginatedResource($paginator, CarrierResource::class)`.
 */
#[OA\Schema(
    schema: 'PaginationMeta',
    title: 'Metadatos de paginación',
    description: 'Metadatos que acompañan a un listado paginado. ResponseHandler los funde en la raíz del sobre, junto a statusCode, message y data, en vez de anidarlos bajo una clave meta. Solo aparecen cuando la petición incluye un limit numérico: sin limit, la respuesta trae únicamente statusCode, message y data.',
    properties: [
        new OA\Property(
            property: 'total',
            description: 'Número total de registros existentes, no los de la página actual.',
            type: 'integer',
            example: 42,
        ),
        new OA\Property(
            property: 'currentPage',
            description: 'Página devuelta. Se controla con el parámetro de query page.',
            type: 'integer',
            example: 1,
        ),
        new OA\Property(
            property: 'lastPage',
            description: 'Número de la última página disponible con el tamaño de página aplicado.',
            type: 'integer',
            example: 5,
        ),
    ],
    type: 'object',
)]
class PaginatedResource extends ResourceCollection
{
    /**
     * @param  class-string<JsonResource>  $resourceClass
     */
    public function __construct(LengthAwarePaginator $paginator, string $resourceClass)
    {
        $this->collects = $resourceClass;

        parent::__construct($paginator);
    }

    /**
     * Transform the resource collection into an array.
     *
     * @return array{data: mixed, total: int, currentPage: int, lastPage: int}
     */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator $paginator */
        $paginator = $this->resource;

        return [
            'data' => $this->collection,
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
        ];
    }
}
