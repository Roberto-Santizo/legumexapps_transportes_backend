<?php

namespace App\Http\Resources\DeparturePoint;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeparturePointResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `latitude` and `longitude` travel as strings with eight decimals — they are the
     * `decimal:8` cast handed over untouched, so no float conversion can drop digits.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'googlePlaceId' => $this->google_place_id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
