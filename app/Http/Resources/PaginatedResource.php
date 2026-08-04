<?php

namespace App\Http\Resources;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Generic pagination envelope, reusable by any resource of the project.
 *
 * Wraps a paginator with the child resource that renders each item:
 * `new PaginatedResource($paginator, CarrierResource::class)`.
 */
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
