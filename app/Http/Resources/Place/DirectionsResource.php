<?php

namespace App\Http\Resources\Place;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The road route towards a registered destination.
 *
 * Wraps an array, not a model, like PlaceResource and FreightQuoteResource: the route
 * comes from the provider and is never persisted.
 *
 * The destination identity does not come from the provider, which never learns that a
 * registered destination exists: locationId and locationName are read from the Location
 * the controller resolved.
 *
 * Nothing here is money and nothing here is a date, so neither the two-decimal money
 * formatting nor the d-m-Y h:i:s A format of the rest of the project applies.
 */
class DirectionsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Location $location */
        $location = $this->resource['location'];

        /** @var array{distanceKilometers: float, durationHours: float, polyline: string, points: list<array{0: float, 1: float}>} $directions */
        $directions = $this->resource['directions'];

        return [
            'locationId' => $location->id,
            'locationName' => $location->name,
            /** Números, no cadenas: no son dinero y no hay cast decimal de por medio. */
            'distanceKilometers' => $directions['distanceKilometers'],
            /** Estimación sin tráfico, calculada sobre límites de velocidad. No es un ETA. */
            'durationHours' => $directions['durationHours'],
            /**
             * La misma línea dos veces, a propósito: la cadena para las librerías de mapa
             * que la consumen directa, los pares para dibujar o medir sin decodificador.
             */
            'polyline' => $directions['polyline'],
            'points' => $directions['points'],
        ];
    }
}
