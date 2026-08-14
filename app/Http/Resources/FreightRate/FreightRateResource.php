<?php

namespace App\Http\Resources\FreightRate;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FreightRateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The zone and the product travel resolved by name so the client never needs a
     * second GET just to label a row of the price table.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'zoneId' => $this->zone_id,
            'zoneName' => $this->zone?->name,
            'productId' => $this->product_id,
            'productName' => $this->product?->name,
            'fuelType' => $this->fuel_type->value,
            /** Rige desde este precio de combustible hacia arriba. */
            'fuelMin' => $this->fuel_min,
            'pricePerPound' => $this->price_per_pound,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
