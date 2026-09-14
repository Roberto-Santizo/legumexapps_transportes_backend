<?php

namespace App\Http\Resources\Dashboard;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleExpensesSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'totalAmount' => $this->resource['totalAmount'],
            'count' => $this->resource['count'],
            'byCategory' => $this->resource['byCategory'],
            'byNature' => $this->resource['byNature'],
            'invoiced' => $this->resource['invoiced'],
            'notInvoiced' => $this->resource['notInvoiced'],
            'byCarrier' => $this->resource['byCarrier'],
            'byMonth' => $this->resource['byMonth'],
        ];
    }
}
