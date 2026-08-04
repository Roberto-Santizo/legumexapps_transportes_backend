<?php

namespace App\Http\Resources\Carrier;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarrierPilotResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * The joined date comes from the carrier_pilots pivot row.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'joinedAt' => $this->pivot?->created_at,
        ];
    }
}
