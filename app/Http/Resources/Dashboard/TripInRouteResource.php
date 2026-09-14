<?php

namespace App\Http\Resources\Dashboard;

use App\Models\TripPosition;
use App\Models\TripTimeout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TripInRouteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** Atributos transitorios puestos por el service: el Resource no consulta nada. */
        $lastPosition = $this->getAttribute('lastPosition');
        $openTimeout = $this->getAttribute('openTimeout');
        $carrier = $this->assignedBy?->carrier;

        return [
            'tripId' => $this->id,
            'order' => $this->order,
            'container' => $this->container,
            'carrierId' => $carrier?->id,
            'carrierName' => $carrier?->name,
            'pilotId' => $this->pilot_id,
            'pilotName' => $this->pilot?->name,
            'vehicleId' => $this->vehicle_id,
            'vehiclePlate' => $this->vehicle?->plate,
            'clientName' => $this->client?->name,
            'locationName' => $this->location?->name,
            'startDate' => $this->start_date?->format('d-m-Y h:i:s A'),
            'lastPosition' => $lastPosition instanceof TripPosition ? [
                'latitude' => $lastPosition->latitude,
                'longitude' => $lastPosition->longitude,
                'recordedAt' => $lastPosition->recorded_at?->format('d-m-Y h:i:s A'),
            ] : null,
            'totalFuelGallons' => number_format((float) ($this->total_fuel_gallons ?? 0), 2, '.', ''),
            'unconfirmedFuelGallons' => number_format((float) ($this->unconfirmed_fuel_gallons ?? 0), 2, '.', ''),
            /**
             * Medido contra now() a propósito, al revés que durationMinutes de la parada
             * histórica: este endpoint es una foto del momento y su único valor es cambiar.
             */
            'openTimeout' => $openTimeout instanceof TripTimeout ? [
                'startedAt' => $openTimeout->started_at?->format('d-m-Y h:i:s A'),
                'latitude' => $openTimeout->latitude,
                'longitude' => $openTimeout->longitude,
                'stoppedMinutes' => round($openTimeout->started_at->diffInSeconds(now()) / 60, 2),
            ] : null,
        ];
    }
}
