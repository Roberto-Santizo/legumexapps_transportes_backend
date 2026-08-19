<?php

namespace App\Http\Resources\Location;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Location',
    title: 'Destino',
    description: <<<'TEXT'
    Destino nacional: un punto concreto del mapa —una bodega, un centro de acopio, un mercado— anclado a un lugar real de Google por su googlePlaceId. NO pertenece a ninguna empresa transportista —es un dato de Legumex, común a todos—, por eso el recurso no expone carrierId ni carrierName y NINGUNA ruta del dominio lleva el middleware carrier.required. Su lectura está abierta a los cuatro roles: administrator, carrier, pilot y manager, incluido un carrier que todavía no ha registrado su empresa.

    Es el eje de las tarifas de flete: cada FreightRate cuelga de un destino (location_id) y GET /api/freight-rates/quote se cotiza mandando este id. ATENCIÓN — las coordenadas NO INFLUYEN EN EL PRECIO: la tarifa depende del destino ELEGIDO, no de dónde esté el pin. Corregir latitude y longitude no cambia ninguna cotización, y de ellas no se deriva distancia, kilometraje ni ruta.

    ATENCIÓN — latitude y longitude viajan como CADENAS con OCHO DECIMALES ("14.63490000"), no como números JSON: son el cast decimal:8 entregado tal cual, para que ningún float pierda dígitos por el camino. Hay que parsearlas en el cliente antes de operar con ellas.

    ATENCIÓN — createdAt y updatedAt NO viajan en ISO 8601, sino con el formato propio d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM), igual que en Products y Zones. Por eso se documentan como string SIN format date-time: parsearlos como ISO falla.

    El name se devuelve SIEMPRE normalizado y EN MAYÚSCULAS. El status es un BOOLEANO cuya baja es LÓGICA: DELETE pone status en false, la fila nunca desaparece y sigue apareciendo en el listado sin filtros.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico de la fila (locations.id). Es el valor que va en el parámetro {location} de las rutas de detalle, actualización, toggle y baja, el que se manda como locationId al cotizar una tarifa y el que se filtra en GET /api/freight-rates?locationId=. Sobrevive a la baja lógica y a un cambio de googlePlaceId: reapuntar el destino a otro lugar conserva el id y sus tarifas.',
            type: 'integer',
            example: 3,
        ),
        new OA\Property(
            property: 'name',
            description: 'Nombre del destino, SIEMPRE EN MAYÚSCULAS. Se normaliza al crear y al actualizar: se recortan los extremos, se colapsan los espacios internos a uno y se pasa a mayúsculas, así que enviar "  bodega   central  " guarda y devuelve "BODEGA CENTRAL". Es único a nivel global, y al estar siempre en mayúsculas la unicidad resulta insensible a mayúsculas sin depender del collation del motor. Es también el nombre que aparece en el mensaje de error 400 cuando otra alta intenta reutilizar su googlePlaceId.',
            type: 'string',
            example: 'BODEGA CENTRAL ESCUINTLA',
        ),
        new OA\Property(
            property: 'description',
            description: 'Descripción libre del destino. Es el ÚNICO campo del recurso que puede ser null: se omite en el alta o se limpia enviando null en el PATCH. No tiene longitud máxima —la columna es text— y no participa en la búsqueda del listado, que solo mira el name.',
            type: 'string',
            nullable: true,
            example: 'Entrada por el km 58, portón de carga 2',
        ),
        new OA\Property(
            property: 'googlePlaceId',
            description: 'Identificador del lugar en Google Places (place id), guardado TAL CUAL LLEGA: no se recorta, no se normaliza y ES SENSIBLE A MAYÚSCULAS, porque es un identificador opaco y cambiarle una letra apuntaría a otro lugar. Es único entre destinos, pero esa unicidad NO la impone el FormRequest sino el service, que responde 400 nombrando al destino que ya lo ocupa. Es EDITABLE: un PATCH puede reapuntar el destino a otro lugar conservando su id y sus tarifas, y no se valida contra latitude/longitude, así que puede quedar desalineado con el pin sin ningún aviso. Se obtiene de GET /api/places.',
            type: 'string',
            example: 'ChIJd8BlQ2BZwokRAFUEcm_qrcA',
        ),
        new OA\Property(
            property: 'latitude',
            description: 'Latitud del destino en grados decimales, en [-90, 90]. ATENCIÓN — viaja como CADENA con OCHO DECIMALES ("14.63490000"), no como número: es el cast decimal:8 entregado sin convertir a float, de modo que ningún dígito se pierde. Ocho decimales dan precisión de poco más de un milímetro. NO influye en el precio: sirve para pintar el pin, no para calcular la tarifa.',
            type: 'string',
            example: '14.63490000',
        ),
        new OA\Property(
            property: 'longitude',
            description: 'Longitud del destino en grados decimales, en [-180, 180]. Mismo tratamiento que latitude: CADENA con OCHO DECIMALES ("-90.50690000"), nunca número JSON. NO influye en el precio ni se cruza con el googlePlaceId: cambiar uno sin el otro es válido y deja el pin desalineado en silencio.',
            type: 'string',
            example: '-90.50690000',
        ),
        new OA\Property(
            property: 'status',
            description: 'Publicación del destino, como BOOLEANO JSON (true o false), nunca como 1/0 ni como cadena. Nace en true y el cuerpo del alta no puede fijarlo. Pasa a false con DELETE /api/locations/{location} —que es baja lógica, no borrado— o con PATCH y status: false, y vuelve a true con PATCH /api/locations/{location}/toggle-status o con PATCH y status: true. Un destino inactivo sigue existiendo, sigue apareciendo en GET /api/locations sin filtros y sigue siendo consultable por id, pero YA NO SE PUEDE COTIZAR: GET /api/freight-rates/quote responde 400 con "El destino seleccionado no está activo" y las tarifas de ese destino quedan congeladas para edición.',
            type: 'boolean',
            example: true,
        ),
        new OA\Property(
            property: 'registeredByName',
            description: 'Nombre del administrador que dio de alta el destino, obtenido de la relación registeredBy. No se envía en el cuerpo: sale del usuario autenticado. El PATCH no lo reescribe, así que sigue apuntando a quien creó la fila aunque la haya editado otro administrador.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'createdAt',
            description: 'Fecha de alta del destino. ATENCIÓN — igual que en Products y Zones: NO viaja en ISO 8601 como en el resto del proyecto, sino con el formato d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM). Por eso se documenta como string sin format date-time: un cliente generado desde este schema que intentara parsearlo como ISO fallaría. Está pensado para mostrarse tal cual.',
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
    schema: 'LocationListResponse',
    title: 'Listado de destinos sin paginar',
    description: 'Respuesta de GET /api/locations cuando no se envía limit o cuando el limit no es numérico: se devuelven todos los destinos que pasen los filtros y el sobre NO incluye total, currentPage ni lastPage. Es la forma que quiere un selector de destinos que necesita la lista entera de una vez. Sin el filtro status, la lista mezcla activos e inactivos, siempre ordenada por id ASC.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Destinos obtenidos correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Location')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedLocationListResponse',
    title: 'Listado de destinos paginado',
    description: 'Respuesta de GET /api/locations cuando se envía un limit numérico: los metadatos de paginación salen APLANADOS en la raíz del sobre, junto a statusCode, message y data, no anidados bajo meta.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/LocationListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class LocationResource extends JsonResource
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
