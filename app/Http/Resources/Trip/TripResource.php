<?php

namespace App\Http\Resources\Trip;

use App\Services\Place\PolylineDecoder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The trip as the API paints it: 31 keys in camelCase, the largest resource of the
 * project.
 *
 * The six relations go out **flat**, as an id plus its name side by side, never as a
 * nested object: `clientId` + `clientName`, `vehicleId` + `vehiclePlate`, and so on.
 * The service loads the eight of them with `with()`, so painting a page of a hundred
 * costs no extra query.
 *
 * The four dates use the project's own `d-m-Y h:i:s A` format —day-month-year with a
 * 12 hour clock and AM/PM—, not ISO 8601: parsing them as ISO fails. `startDate` and
 * `endDate` stay null until the pilot actually starts and closes the trip.
 *
 * `deletedAt` is null on seven of the eight endpoints, because none of them can reach
 * a deleted trip. The exception is the response of the DELETE itself, which paints the
 * row that was just soft deleted.
 */
class TripResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `points` is the second computed read-only field of the project, after the
     * `currentValue` of an accessory: it is decoded from `polyline` on every read, with
     * no column, no job and no cache. Storing the decoded pairs would be the same data
     * twice, free to fall out of sync with the string it came from.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order' => $this->order,
            /** El valor crudo del enum, en inglés: traducirlo es cosa del frontend. */
            'status' => $this->status?->value,
            'clientId' => $this->client_id,
            'clientName' => $this->client?->name,
            'shippingLineId' => $this->shipping_line_id,
            'shippingLineName' => $this->shippingLine?->name,
            'departurePointId' => $this->departure_point_id,
            'departurePointName' => $this->departurePoint?->name,
            'locationId' => $this->location_id,
            'locationName' => $this->location?->name,
            'destination' => $this->destination,
            'container' => $this->container,
            'transport' => $this->transport,
            'recolectionDate' => $this->recolection_date?->format('d-m-Y h:i:s A'),
            'shipDate' => $this->ship_date?->format('d-m-Y h:i:s A'),
            'startDate' => $this->start_date?->format('d-m-Y h:i:s A'),
            'endDate' => $this->end_date?->format('d-m-Y h:i:s A'),
            'polyline' => $this->polyline,
            /** Los mismos pares que devuelve GET /api/places/directions para esta cadena. */
            'points' => PolylineDecoder::decode($this->polyline),
            'observations' => $this->observations,
            'pilotId' => $this->pilot_id,
            'pilotName' => $this->pilot?->name,
            'vehicleId' => $this->vehicle_id,
            'vehiclePlate' => $this->vehicle?->plate,
            'assignedById' => $this->assigned_by,
            'assignedByName' => $this->assignedBy?->name,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
            'deletedAt' => $this->deleted_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
