<?php

namespace App\Http\Resources\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripsSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'total' => $this->resource['total'],
            'unassigned' => $this->resource['unassigned'],
            'byStatus' => $this->resource['byStatus'],
            'byCarrier' => $this->resource['byCarrier'],
            'byClient' => $this->resource['byClient'],
            'byShippingLine' => $this->resource['byShippingLine'],
            'byLocation' => $this->resource['byLocation'],
            'byMonth' => $this->resource['byMonth'],
        ];
    }
}
