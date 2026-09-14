<?php

namespace App\Http\Resources\Dashboard;

use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardVehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** Atributo transitorio puesto por el service: el Resource no consulta nada. */
        $currentTrip = $this->getAttribute('currentTrip');

        return [
            'id' => $this->id,
            'plate' => $this->plate,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'condition' => $this->condition->value,
            'mileage' => $this->mileage,
            'kilometersPerGallon' => $this->kilometers_per_gallon,
            'carrierId' => $this->carrier_id,
            'carrierName' => $this->carrier?->name,
            'inRoute' => $currentTrip instanceof Trip,
            'currentTrip' => $currentTrip instanceof Trip ? [
                'tripId' => $currentTrip->id,
                'order' => $currentTrip->order,
                'container' => $currentTrip->container,
                'pilotName' => $currentTrip->pilot?->name,
                'startDate' => $currentTrip->start_date?->format('d-m-Y h:i:s A'),
            ] : null,
        ];
    }
}
