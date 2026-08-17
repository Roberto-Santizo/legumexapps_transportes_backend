<?php

namespace App\Http\Resources\Place;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

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
#[OA\Schema(
    schema: 'PlacePrediction',
    title: 'Dirección sugerida',
    description: <<<'TEXT'
    Una de las direcciones que devuelve GET /api/places?search=. Es el PRIMER paso de un flujo de DOS llamadas: aquí se elige, y con el id elegido se pide GET /api/places/{place} para obtener las coordenadas.

    ATENCIÓN — ESTE OBJETO NO TRAE COORDENADAS, y es a propósito: pedirle la posición al proveedor de direcciones para los diez resultados factura un nivel más caro por nueve posiciones que nadie usa. El usuario elige una sola dirección, y solo esa se resuelve.

    NO PERSISTE NADA. Este dominio no tiene tabla, ni modelo, ni migración: es un proxy de lectura sobre un servicio externo. La dirección no queda guardada en ningún sitio, no hay favoritos ni historial de búsquedas, y el mismo search mañana puede devolver otras direcciones o el mismo id con otro texto.

    Ningún campo es fecha, así que el formato d-m-Y h:i:s A del resto del proyecto no aplica aquí.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador OPACO de la dirección tal y como lo entrega el proveedor externo. ATENCIÓN — NO es un entero autoincremental como el id del resto de recursos de esta API: es una CADENA sin significado, sin formato garantizado y sin forma de adivinarse ni construirse. La única manera de obtener uno es esta búsqueda. Es lo que se pasa como {place} a GET /api/places/{place}, y lo único de la respuesta que el cliente puede guardar entre las dos llamadas.',
            type: 'string',
            example: 'ChIJk4h8_Q6ii4ARZ4gGpXY8bJ0',
        ),
        new OA\Property(
            property: 'formattedAddress',
            description: 'Dirección completa ya formateada y lista para pintar en pantalla, en español y sesgada a Guatemala —lo que prioriza resultados guatemaltecos sin excluir los de otros países—. Llega en una sola cadena: no viene desglosada en calle, número, municipio ni departamento, y no hay ningún otro campo del lugar (nombre comercial, tipos, horarios, teléfono o fotos). Es texto de presentación: no se busca ni se filtra por él.',
            type: 'string',
            example: '5a Avenida 12-38, Zona 4, Ciudad de Guatemala',
        ),
    ],
    type: 'object',
)]
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
