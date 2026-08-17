<?php

namespace App\Http\Resources\Place;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One address of the search listing.
 *
 * Like FreightQuoteResource this one does NOT wrap a model: it wraps the array the
 * place contract returns, whose keys already arrive in camelCase because no Eloquent
 * row sits in between.
 *
 * It carries no coordinates on purpose: asking the provider for the position of the
 * ten results bills a more expensive tier for nine positions nobody uses.
 */
class PlacePredictionResource extends JsonResource
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
        ];
    }
}
