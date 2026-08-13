<?php

namespace App\Http\Resources\Zone;

use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ZoneResource extends JsonResource
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
            'description' => $this->description,
            'color' => $this->color,
            'area' => $this->resolveArea(),
            'status' => $this->status,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
        ];
    }

    /**
     * Return the polygon as `[lat, lng]` pairs with an open ring.
     *
     * A model built without the calculated column has no polygon to show; that is a
     * programming mistake, and it must not turn into a 500 in production, so it
     * degrades into an empty array.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    private function resolveArea(): array
    {
        $geoJson = $this->resource->area_geojson ?? null;

        return is_string($geoJson) ? Zone::geoJsonToPairs($geoJson) : [];
    }
}
