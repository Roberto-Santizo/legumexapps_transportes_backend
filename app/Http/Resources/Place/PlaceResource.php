<?php

namespace App\Http\Resources\Place;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The chosen address with its position.
 *
 * The coordinates come out FLAT and as numbers, not nested under a location key nor
 * rendered as strings: they are not money, no decimal cast is involved, and latitude
 * and longitude are exactly what the client forwards as lat and lng to the freight
 * quote endpoint.
 */
class PlaceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'formattedAddress' => $this->resource['formattedAddress'],
            'latitude' => $this->resource['latitude'],
            'longitude' => $this->resource['longitude'],
        ];
    }
}
