<?php

namespace App\Http\Resources\TripFinishedProduct;

use App\Models\TripFinishedProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TripFinishedProduct
 */
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
