<?php

namespace App\Http\Resources\FuelPrice;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
