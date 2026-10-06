<?php

namespace App\Http\Resources\TripPosition;

use App\Models\TripPosition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'TripPositionBatchResource',
    title: 'Resumen de un lote de posiciones',
    description: <<<'TEXT'
    Lo que pasó con un lote enviado a POST /api/trips/{trip}/positions. CUATRO CLAVES en camelCase y ninguna más, en este orden: received, saved, discarded y lastPosition.

    ATENCIÓN — NO DEVUELVE LOS PUNTOS ESCRITOS. Serían hasta mil objetos que la app ya tiene en su cola. Para vaciarla basta con este resumen: todo lo enviado quedó guardado o se descartó por sobrar, y en ambos casos ya no hay que reenviarlo.

    SIEMPRE SE CUMPLE received === saved + discarded. discarded junta los dos motivos sin desglosarlos: puntos con recordedAt menor o igual al último ya guardado (un reintento) y puntos a menos de 5 s del anterior conservado (el piso). Para la app los dos significan «ya lo tengo o sobraba».

    lastPosition NUNCA ES null: si se escribió algo, es el último punto escrito; si todo se descartó, es el último punto que ya estaba guardado. Tiene las cinco claves de TripPosition.
    TEXT,
    properties: [
        new OA\Property(
            property: 'received',
            description: 'Cuántos puntos llegaron en el arreglo positions, tal cual se enviaron.',
            type: 'integer',
            example: 20,
        ),
        new OA\Property(
            property: 'saved',
            description: 'Cuántos puntos se escribieron en trip_positions. Con saved >= 1 la respuesta es 201; con 0, 200.',
            type: 'integer',
            example: 17,
        ),
        new OA\Property(
            property: 'discarded',
            description: 'Cuántos puntos se descartaron en silencio: anteriores o iguales al último guardado, o a menos de 5 s del anterior conservado. Sin desglose por motivo.',
            type: 'integer',
            example: 3,
        ),
        new OA\Property(
            property: 'lastPosition',
            ref: '#/components/schemas/TripPosition',
            description: 'El último punto del rastro tras la petición. Nunca es null.',
        ),
    ],
    type: 'object',
)]
class TripPositionBatchResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Wraps the summary array returned by the service, not a model, like
     * `TripsSummaryResource` and `TripCostResource`.
     *
     * @return array{received: int, saved: int, discarded: int, lastPosition: TripPositionResource}
     */
    public function toArray(Request $request): array
    {
        /** @var array{received: int, saved: int, discarded: int, lastPosition: TripPosition} $summary */
        $summary = $this->resource;

        return [
            'received' => $summary['received'],
            'saved' => $summary['saved'],
            'discarded' => $summary['discarded'],
            'lastPosition' => new TripPositionResource($summary['lastPosition']),
        ];
    }
}
