<?php

namespace App\Http\Resources\Place;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

/**
 * The chosen address with its position.
 *
 * The coordinates come out FLAT and as numbers, not nested under a location key nor
 * rendered as strings: they are not money, no decimal cast is involved, and latitude
 * and longitude are exactly what the client forwards as lat and lng to the freight
 * quote endpoint.
 */
#[OA\Schema(
    schema: 'Place',
    title: 'Dirección con coordenadas',
    description: <<<'TEXT'
    Respuesta de GET /api/places/{place}: la dirección elegida en la búsqueda, ya con su posición. Es el SEGUNDO paso del flujo de dos llamadas —buscar con GET /api/places?search=, elegir un id, pedir aquí sus coordenadas— y el motivo por el que existe el dominio.

    ATENCIÓN — latitude y longitude son NÚMEROS, no cadenas, y salen PLANOS EN LA RAÍZ de data: no van anidados bajo geometry, ni bajo location, ni bajo ninguna otra clave. No son dinero, no hay cast decimal de por medio y no se formatean con dos decimales como los importes del resto de la API: son la posición tal cual la entregó el proveedor externo.

    SU DESTINO NATURAL es la cotización: latitude y longitude se pasan tal cual como lat y lng a GET /api/freight-rates/quote. Ese encadenado lo hace el cliente; ESTA API NO COTIZA NADA y no llama a la cotización por su cuenta.

    NO SE VALIDA NADA DE LA DIRECCIÓN. El punto puede caer fuera de toda zona registrada, o incluso fuera de Guatemala, y esta respuesta lo devuelve igual con 200. Si eso pasa, quien avisa es la cotización, con su propio 404 «El punto indicado no pertenece a ninguna zona registrada», ya con la dirección elegida en pantalla.

    NO PERSISTE NADA: no hay tabla, ni modelo, ni migración. Consultar una dirección no la guarda, no crea un destino y no registra ningún viaje. Ningún campo es fecha, así que el formato d-m-Y h:i:s A del resto del proyecto no aplica aquí.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'El mismo identificador OPACO que se envió en la ruta, devuelto para que el cliente confirme qué dirección resolvió. Es una CADENA del proveedor externo, no un entero autoincremental, y solo se obtiene de GET /api/places?search=.',
            type: 'string',
            example: 'ChIJk4h8_Q6ii4ARZ4gGpXY8bJ0',
        ),
        new OA\Property(
            property: 'formattedAddress',
            description: 'Dirección completa formateada, la misma clave y el mismo formato que en el listado de búsqueda. Puede no ser idéntica carácter a carácter a la que se mostró al elegir: el texto lo compone el proveedor externo en cada llamada. Lo que se pinta al usuario es este valor, no el que tecleó.',
            type: 'string',
            example: '5a Avenida 12-38, Zona 4, Ciudad de Guatemala',
        ),
        new OA\Property(
            property: 'latitude',
            description: 'LATITUD de la dirección, como NÚMERO en grados decimales. Es lo que se envía como lat a GET /api/freight-rates/quote, sin convertir ni redondear. Nunca es null: si el proveedor externo devuelve la dirección sin posición, la respuesta entera es 503 en vez de unas coordenadas vacías que la cotización rechazaría después con un mensaje confuso.',
            type: 'number',
            format: 'float',
            example: 14.6248,
        ),
        new OA\Property(
            property: 'longitude',
            description: 'LONGITUD de la dirección, como NÚMERO en grados decimales. Es lo que se envía como lng a GET /api/freight-rates/quote. Cuidado con el orden al reenviarla: aquí cada coordenada viaja con su nombre, pero el area de Zones usa pares [latitud, longitud]. Tampoco es null nunca, por la misma razón que latitude.',
            type: 'number',
            format: 'float',
            example: -90.5152,
        ),
    ],
    type: 'object',
)]
class PlaceResource extends JsonResource
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
            'latitude' => $this->resource['latitude'],
            'longitude' => $this->resource['longitude'],
        ];
    }
}
