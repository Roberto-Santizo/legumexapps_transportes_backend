<?php

namespace App\Http\Resources\FreightRate;

use App\Models\FreightRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The answer to "how much does a pound cost up to this point".
 *
 * Unlike every other resource of the project this one does NOT wrap a model: it wraps
 * the array the service returns, because the three values that make the quote auditable
 * —the fuel price in effect, the band that was picked and the total— do not live in any
 * single row.
 */
class FreightQuoteResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FreightRate $rate */
        $rate = $this->resource['rate'];

        $pounds = $this->resource['pounds'] ?? null;
        $total = $this->resource['total'] ?? null;

        return [
            'freightRateId' => $rate->id,
            'zoneId' => $rate->zone_id,
            'zoneName' => $rate->zone?->name,
            'productId' => $rate->product_id,
            'productName' => $rate->product?->name,
            'fuelType' => $rate->fuel_type->value,
            /** El FuelPrice active al momento de consultar; nunca sale de la petición. */
            'currentFuelPrice' => $this->resource['currentFuelPrice'],
            /** La banda elegida: su distancia con currentFuelPrice delata una tarifa vieja. */
            'appliedFuelMin' => $rate->fuel_min,
            'pricePerPound' => $rate->price_per_pound,
            'pounds' => $this->money($pounds),
            'total' => $this->money($total),
        ];
    }

    /**
     * Render an amount with the two decimals money is read with, keeping null as null.
     */
    private function money(int|float|null $amount): ?string
    {
        return $amount === null ? null : number_format($amount, 2, '.', '');
    }
}
