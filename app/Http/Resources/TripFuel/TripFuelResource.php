<?php

namespace App\Http\Resources\TripFuel;

use App\Models\TripFuel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TripFuel
 */
class TripFuelResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Eight keys in camelCase. `isConfirmed` is **derived** from `loaded_at` and has no
     * column of its own, the same way `currentValue` is derived in SPEC 17: the two
     * states of a load are «unconfirmed» and «confirmed», and a `status` column could
     * only end up contradicting the date.
     *
     * `gallons` leaves as a two decimal string —the model deliberately does not cast
     * it—, like `price` in SPEC 17 and `salary` in SPEC 11, and `fuelType` leaves with
     * the **raw enum value in English**, untranslated, like `LocationType` in SPEC 21
     * and `TripStatus` in SPEC 24.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            /** Sí viaja, a diferencia del rastro de SPEC 26: la confirmación se pide por /api/trip-fuels/{tripFuel}, fuera del viaje. */
            'tripId' => $this->trip_id,
            'gallons' => number_format((float) $this->gallons, 2, '.', ''),
            'fuelType' => $this->fuel_type?->value,
            /** Derivado, sin columna: null en `loaded_at` es «sin confirmar» y nada más. */
            'isConfirmed' => $this->loaded_at !== null,
            /** El formato de fecha del resto del dominio, nunca ISO 8601. */
            'loadedAt' => $this->loaded_at?->format('d-m-Y h:i:s A'),
            'confirmedByName' => $this->confirmedBy?->name,
            'registeredByName' => $this->registeredBy?->name,
        ];
    }
}
