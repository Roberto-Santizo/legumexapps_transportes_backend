<?php

namespace App\Http\Resources\Zone;

use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'Zone',
    title: 'Zona geográfica',
    description: <<<'TEXT'
    Zona geográfica nacional dibujada como un polígono. NO pertenece a ninguna empresa transportista —es un dato de Legumex, común a todos los transportistas—, por eso el recurso no expone carrierId ni carrierName y su lectura está abierta a los cuatro roles: administrator, carrier, pilot y manager, incluido un carrier que todavía no ha registrado su empresa.

    ATENCIÓN — el área viaja como un ARRAY DE PARES [latitud, longitud] CON EL ANILLO ABIERTO. Se envían 3 o más puntos y se devuelven exactamente esos mismos puntos, en el mismo orden y SIN repetir el primero al final. El orden interno de PostGIS (longitud primero) y el punto de cierre repetido son detalles del motor que no salen nunca de la capa de servicio: el cliente solo ve [lat, lng] abierto.

    El name se devuelve SIEMPRE normalizado y EN MAYÚSCULAS, y el color SIEMPRE llega con valor —nunca null—, porque una zona sin color explícito nace con el azul por defecto de Leaflet (#3388FF).

    El status es un BOOLEANO cuya baja es LÓGICA: DELETE pone status en false, la fila nunca desaparece y sigue apareciendo en el listado sin filtros.

    Requisito de entorno: este dominio se apoya en PostgreSQL con la extensión PostGIS; sin ella la tabla no existe y ninguno de los seis endpoints funciona.
    TEXT,
    properties: [
        new OA\Property(
            property: 'id',
            description: 'Identificador numérico de la fila (zones.id). Es el valor que va en el parámetro {zone} de las rutas de detalle, actualización, toggle y baja.',
            type: 'integer',
            example: 1,
        ),
        new OA\Property(
            property: 'name',
            description: 'Nombre de la zona, SIEMPRE EN MAYÚSCULAS. Se normaliza al crear y al actualizar: se recortan los extremos, se colapsan los espacios internos a uno y se pasa a mayúsculas, así que enviar "  zona   norte  " guarda y devuelve "ZONA NORTE". Es único a nivel global, y al estar siempre en mayúsculas la unicidad resulta insensible a mayúsculas sin depender del collation del motor.',
            type: 'string',
            example: 'ZONA NORTE',
        ),
        new OA\Property(
            property: 'description',
            description: 'Descripción libre de la zona, hasta 1000 caracteres. Es el único campo del recurso que puede ser null: se omite en el alta o se limpia enviando null en el PATCH.',
            type: 'string',
            nullable: true,
            example: 'Cobertura del norte del área metropolitana',
        ),
        new OA\Property(
            property: 'color',
            description: 'Color con el que se pinta la zona en un mapa, en hexadecimal #RRGGBB y SIEMPRE EN MAYÚSCULAS. NUNCA es null: si el alta no lo envía, la zona nace con #3388FF —el azul por defecto de Leaflet—. Es dato de presentación, sin ninguna semántica de negocio.',
            type: 'string',
            example: '#3388FF',
        ),
        new OA\Property(
            property: 'area',
            description: <<<'TEXT'
            Polígono de la zona como lista de pares [latitud, longitud] CON EL ANILLO ABIERTO: si se enviaron 3 puntos se devuelven esos 3, en el mismo orden, sin un cuarto punto repetido para cerrar la figura. Siempre hay al menos 3 pares y cada par tiene exactamente 2 números.

            ATENCIÓN — el orden es LATITUD PRIMERO, al revés que GeoJSON, Leaflet en su forma [lng, lat], Mapbox y turf. Invertir el par suele fallar con 422 y el mensaje "La latitud del punto N debe estar entre -90 y 90", porque casi cualquier longitud de Guatemala (-90) cae fuera del rango de latitud; pero un punto genuinamente ambiguo, con los dos valores dentro de [-90, 90], se guarda sin error y coloca la zona en el sitio equivocado del mapa. Es el error más caro de este dominio: no hay excepción, no hay log, solo un polígono mal ubicado.

            Rangos: la latitud va en [-90, 90] y la longitud en [-180, 180]. Un modelo cargado sin la columna calculada del polígono degrada a lista vacía en vez de provocar un 500; ver un area vacía en una respuesta indica un fallo de carga, no una zona sin geometría.
            TEXT,
            type: 'array',
            items: new OA\Items(
                description: 'Par [latitud, longitud]: exactamente dos números, la latitud primero.',
                type: 'array',
                items: new OA\Items(type: 'number', format: 'float'),
                maxItems: 2,
                minItems: 2,
            ),
            example: [[14.6349, -90.5069], [14.6402, -90.4998], [14.6281, -90.4931]],
        ),
        new OA\Property(
            property: 'status',
            description: 'Publicación de la zona, como BOOLEANO JSON (true o false), nunca como 1/0 ni como cadena. Nace en true y el cuerpo del alta no puede fijarlo. Pasa a false con DELETE /api/zones/{zone} —que es baja lógica, no borrado— o con PATCH y status: false, y vuelve a true con PATCH /api/zones/{zone}/toggle-status o con PATCH y status: true. Una zona inactiva sigue existiendo, sigue apareciendo en GET /api/zones sin filtros, sigue siendo consultable por id y sigue saliendo en el filtro lat+lng.',
            type: 'boolean',
            example: true,
        ),
        new OA\Property(
            property: 'registeredByName',
            description: 'Nombre del administrador que dio de alta la zona, obtenido de la relación registeredBy. No se envía en el cuerpo: sale del usuario autenticado. El PATCH no lo reescribe, así que sigue apuntando a quien creó la fila aunque la haya editado otro administrador.',
            type: 'string',
            nullable: true,
            example: 'Roberto Santizo',
        ),
        new OA\Property(
            property: 'createdAt',
            description: 'Fecha de alta de la zona. ATENCIÓN — igual que en Products: NO viaja en ISO 8601 como en el resto del proyecto, sino con el formato d-m-Y h:i:s A (día-mes-año y hora de 12 horas con AM/PM). Por eso se documenta como string sin format date-time: un cliente generado desde este schema que intentara parsearlo como ISO fallaría. Está pensado para mostrarse tal cual.',
            type: 'string',
            nullable: true,
            example: '07-08-2026 06:03:22 PM',
        ),
        new OA\Property(
            property: 'updatedAt',
            description: 'Fecha del último cambio de cualquiera de los campos editables, incluido el polígono; es lo más cercano a una auditoría que ofrece el dominio, porque no se guarda el área anterior ni ningún valor previo. Mismo formato propio d-m-Y h:i:s A que createdAt, tampoco ISO 8601.',
            type: 'string',
            nullable: true,
            example: '07-08-2026 06:11:40 PM',
        ),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ZoneListResponse',
    title: 'Listado de zonas sin paginar',
    description: 'Respuesta de GET /api/zones cuando no se envía limit o cuando el limit no es numérico: se devuelven todas las zonas que pasen los filtros y el sobre NO incluye total, currentPage ni lastPage. Es la forma que quiere un mapa que necesita pintar todas las zonas de una vez. Sin el filtro status, la lista mezcla activas e inactivas, y cada elemento trae su polígono completo.',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
        new OA\Property(property: 'message', type: 'string', example: 'Zonas obtenidas correctamente'),
        new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/Zone')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'PaginatedZoneListResponse',
    title: 'Listado de zonas paginado',
    description: 'Respuesta de GET /api/zones cuando se envía un limit numérico: los metadatos de paginación salen APLANADOS en la raíz del sobre, junto a statusCode, message y data, no anidados bajo meta.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/ZoneListResponse'),
        new OA\Schema(ref: '#/components/schemas/PaginationMeta'),
    ],
)]
class ZoneResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
            'area' => $this->resolveArea(),
            'status' => $this->status,
            'registeredByName' => $this->registeredBy?->name,
            'createdAt' => $this->created_at?->format('d-m-Y h:i:s A'),
            'updatedAt' => $this->updated_at?->format('d-m-Y h:i:s A'),
        ];
    }

    /**
     * Return the polygon as `[lat, lng]` pairs with an open ring.
     *
     * A model built without the calculated column has no polygon to show; that is a
     * programming mistake, and it must not turn into a 500 in production, so it
     * degrades into an empty array.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    private function resolveArea(): array
    {
        $geoJson = $this->resource->area_geojson ?? null;

        return is_string($geoJson) ? Zone::geoJsonToPairs($geoJson) : [];
    }
}
