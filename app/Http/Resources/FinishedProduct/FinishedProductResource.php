<?php

namespace App\Http\Resources\FinishedProduct;

use App\Models\FinishedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinishedProduct
 */
class FinishedProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `clientName` comes through a relation that reads trashed clients, so a SKU keeps
     * its client's name after that client is deleted. `deletedAt` only carries a date
     * in the response of the DELETE itself.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'presentation' => number_format((float) $this->presentation, 2, '.', ''),
            'boxesPerPallet' => number_format((float) $this->boxes_per_pallet, 2, '.', ''),
            'clientId' => $this->client_id,
            'clientName' => $this->client?->name,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
            'deletedAt' => $this->deleted_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
