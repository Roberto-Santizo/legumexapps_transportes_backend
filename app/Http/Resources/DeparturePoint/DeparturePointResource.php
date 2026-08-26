<?php

namespace App\Http\Resources\DeparturePoint;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'DeparturePoint',
    title: 'Punto de partida',
    description: <<<'TEXT'
    Punto de partida: el lugar concreto del mapa desde el que ARRANCA un viaje —una bodega, una finca, un centro de acopio—, anclado a un lugar real de Google por su googlePlaceId. NO pertenece a ninguna empresa transportista —es un dato de Legumex, común a todos—, por eso el recurso no expone carrierId ni carrierName y NINGUNA ruta del dominio lleva el middleware carrier.required. Su lectura está abierta a los cuatro roles: administrator, carrier, pilot y manager, incluido un carrier que todavía no ha registrado su empresa.

    ATENCIÓN — ESTE RECURSO Y Location SON INDISTINGUIBLES POR SU FORMA, y esa es la trampa número uno del dominio. Los dos devuelven EXACTAMENTE LAS MISMAS DIEZ CLAVES, con los mismos tipos y los mismos formatos, así que llamar al catálogo equivocado NO FALLA: devuelve datos plausibles del otro dominio y el error solo se descubre cuando el usuario ve un nombre que no esperaba. Un punto de partida es DE DÓNDE SALE el viaje y vive en la tabla departure_points; un destino es A DÓNDE LLEGA y vive en locations. Los id de las dos tablas NO SON INTERCAMBIABLES: son secuencias distintas, y el id 3 de este recurso no tiene nada que ver con el id 3 de Location. Nunca se debe pasar un id de aquí a GET /api/locations/{location} ni al locationId de una cotización.

    NO PARTICIPA EN NINGUNA COTIZACIÓN: a diferencia de Location, no hay FreightRate colgando de un punto de partida, ninguna tabla apunta a él y GET /api/freight-rates/quote no lo recibe. Sus coordenadas sirven para pintar el pin y nada más: de ellas no se deriva distancia, kilometraje, ruta ni precio.

    ATENCIÓN — latitude y longitude viajan como CADENAS con OCHO DECIMALES ("14.63490000"), no como números JSON: son el cast decimal:8 entregado tal cual, para que ningún float pierda dígitos por el camino. Hay que parsearlas en el cliente antes de operar con ellas.

    ATENCIÓN — createdAt y updatedAt NO viajan en ISO 8601, sino con el formato propio d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM), igual que en Locations, Products y Zones. Por eso se documentan como string SIN format date-time: parsearlos como ISO falla.

    El name se devuelve SIEMPRE normalizado y EN MAYÚSCULAS. El status es un BOOLEANO cuya baja es LÓGICA: DELETE pone status en false, la fila nunca desaparece y sigue apareciendo en el listado sin filtros.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico de la fila (departure_points.id). Es el valor que va en el parámetro {departurePoint} de las rutas de detalle, actualización, toggle y baja. ATENCIÓN — NO ES INTERCAMBIABLE CON EL id DE UN Location: son tablas distintas con secuencias distintas, y usar uno donde va el otro devuelve la fila equivocada con 200 en vez de un error. Sobrevive a la baja lógica y a un cambio de googlePlaceId: reapuntar el punto a otro lugar conserva el id.',
            type: 'integer',
            example: 3,
        ),
        new OA\Property(
            property: 'name',
            description: 'Nombre del punto de partida, SIEMPRE EN MAYÚSCULAS. Se normaliza al crear y al actualizar: se recortan los extremos, se colapsan los espacios internos a uno y se pasa a mayúsculas, así que enviar "  bodega   central  " guarda y devuelve "BODEGA CENTRAL". Es único DENTRO DE ESTA TABLA, y al estar siempre en mayúsculas la unicidad resulta insensible a mayúsculas sin depender del collation del motor. ATENCIÓN — la unicidad NO se cruza con locations: el mismo nombre puede existir a la vez como punto de partida y como destino, y el alta responde 201 sin ningún aviso. Es también el nombre que aparece en el mensaje de error 400 cuando otra alta intenta reutilizar su googlePlaceId.',
            type: 'string',
            example: 'BODEGA CENTRAL ESCUINTLA',
        ),
        new OA\Property(
            property: 'description',
            description: 'Descripción libre del punto de partida. Es el ÚNICO campo del recurso que puede ser null: se omite en el alta o se limpia enviando null en el PATCH. No tiene longitud máxima —la columna es text— y no participa en la búsqueda del listado, que solo mira el name.',
            type: 'string',
            nullable: true,
            example: 'Entrada por el km 58, portón de carga 2',
        ),
        new OA\Property(
            property: 'googlePlaceId',
            description: 'Identificador del lugar en Google Places (place id), guardado TAL CUAL LLEGA: no se recorta, no se normaliza y ES SENSIBLE A MAYÚSCULAS, porque es un identificador opaco y cambiarle una letra apuntaría a otro lugar. Es único entre puntos de partida, pero esa unicidad NO la impone el FormRequest sino el service, que responde 400 nombrando al punto que ya lo ocupa. ATENCIÓN — la comprobación mira SOLO la tabla departure_points: el mismo lugar puede estar dado de alta a la vez como punto de partida y como destino, y eso no es conflicto. Es EDITABLE: un PATCH puede reapuntar el punto a otro lugar conservando su id, y no se valida contra latitude/longitude, así que puede quedar desalineado con el pin sin ningún aviso. Se obtiene de GET /api/places.',
            type: 'string',
            example: 'ChIJd8BlQ2BZwokRAFUEcm_qrcA',
        ),
        new OA\Property(
            property: 'latitude',
            description: 'Latitud del punto de partida en grados decimales, en [-90, 90]. ATENCIÓN — viaja como CADENA con OCHO DECIMALES ("14.63490000"), no como número: es el cast decimal:8 entregado sin convertir a float, de modo que ningún dígito se pierde. Ocho decimales dan precisión de poco más de un milímetro. Sirve para pintar el pin y nada más: ningún cálculo del proyecto la consume.',
            type: 'string',
            example: '14.63490000',
        ),
        new OA\Property(
            property: 'longitude',
            description: 'Longitud del punto de partida en grados decimales, en [-180, 180]. Mismo tratamiento que latitude: CADENA con OCHO DECIMALES ("-90.50690000"), nunca número JSON. No se cruza con el googlePlaceId: cambiar uno sin el otro es válido y deja el pin desalineado en silencio.',
            type: 'string',
            example: '-90.50690000',
        ),
        new OA\Property(
            property: 'status',
            description: 'Publicación del punto de partida, como BOOLEANO JSON (true o false), nunca como 1/0 ni como cadena. Nace en true y el cuerpo del alta no puede fijarlo. Pasa a false con DELETE /api/departure-points/{departurePoint} —que es baja lógica, no borrado— o con PATCH y status: false, y vuelve a true con PATCH /api/departure-points/{departurePoint}/toggle-status o con PATCH y status: true. Un punto inactivo sigue existiendo, sigue apareciendo en GET /api/departure-points sin filtros y sigue siendo consultable por id. ATENCIÓN — desactivarlo NO BLOQUEA NADA en el resto de la API: no hay tarifas ni cotizaciones que dependan de él, así que el status es solo una marca de publicación para que el cliente lo oculte de sus selectores.',
            type: 'boolean',
            example: true,
        ),
        new OA\Property(
            property: 'registeredByName',
            description: 'Nombre del administrador que dio de alta el punto de partida, obtenido de la relación registeredBy. No se envía en el cuerpo: sale del usuario autenticado. El PATCH no lo reescribe, así que sigue apuntando a quien creó la fila aunque la haya editado otro administrador.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'createdAt',
            description: 'Fecha de alta del punto de partida. ATENCIÓN — igual que en Locations, Products y Zones: NO viaja en ISO 8601 como en el resto del proyecto, sino con el formato d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM). Por eso se documenta como string sin format date-time: un cliente generado desde este schema que intentara parsearlo como ISO fallaría. Está pensado para mostrarse tal cual.',
            type: 'string',
            nullable: true,
            example: '19-08-2026 08:45:12 PM',
        ),
        new OA\Property(
            property: 'updatedAt',
            description: 'Fecha del último cambio de cualquiera de los campos editables, incluidos el googlePlaceId y las coordenadas; es lo más cercano a una auditoría que ofrece el dominio, porque no se guarda ningún valor anterior. Mismo formato propio d-m-Y h:i:s A que createdAt, tampoco ISO 8601.',
            type: 'string',
            nullable: true,
            example: '19-08-2026 09:02:40 PM',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'DeparturePointListResponse',
    title: 'Listado de puntos de partida sin paginar',
    description: 'Respuesta de GET /api/departure-points cuando no se envía limit o cuando el limit no es numérico: se devuelven todos los puntos de partida que pasen los filtros y el sobre NO incluye total, currentPage ni lastPage. Es la forma que quiere un selector de origen que necesita la lista entera de una vez. Sin el filtro status, la lista mezcla activos e inactivos, siempre ordenada por id ASC.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Puntos de partida obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DeparturePoint')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedDeparturePointListResponse',
    title: 'Listado de puntos de partida paginado',
    description: 'Respuesta de GET /api/departure-points cuando se envía un limit numérico: los metadatos de paginación salen APLANADOS en la raíz del sobre, junto a statusCode, message y data, no anidados bajo meta.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/DeparturePointListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class DeparturePointResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * `latitude` and `longitude` travel as strings with eight decimals — they are the
     * `decimal:8` cast handed over untouched, so no float conversion can drop digits.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'googlePlaceId' => $this->google_place_id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'status' => $this->status,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
        ];
    }
}
