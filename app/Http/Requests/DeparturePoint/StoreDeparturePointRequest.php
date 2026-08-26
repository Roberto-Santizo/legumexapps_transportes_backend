<?php

namespace App\Http\Requests\DeparturePoint;

use App\Models\DeparturePoint;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreDeparturePointRequest',
    title: 'Alta de punto de partida',
    description: <<<'TEXT'
    Cuerpo JSON para dar de alta un punto de partida —el lugar desde el que ARRANCA un viaje—. Los campos aceptados son name, description, googlePlaceId, latitude y longitude; todos son obligatorios salvo description.

    ATENCIÓN — NO CONFUNDIR CON POST /api/locations. El cuerpo de las dos altas es IDÉNTICO campo por campo, así que equivocarse de endpoint NO produce ningún error: crea la fila en la tabla equivocada y responde 201. Aquí se dan de alta ORÍGENES (departure_points); los DESTINOS van en /api/locations.

    El status NO se acepta: el punto nace siempre activo (true) y enviarlo se descarta sin error, así que no hay forma de crear un punto ya dado de baja. El registeredBy tampoco se envía: se resuelve desde el usuario autenticado, que por el middleware role:administrator es siempre un administrador; mandarlo en el cuerpo no cambia nada.

    ATENCIÓN — ASIMETRÍA DELIBERADA ENTRE LOS DOS DUPLICADOS POSIBLES. El name duplicado es 422 (regla unique del FormRequest, mensaje "Ya existe un punto de partida con ese nombre"); el googlePlaceId duplicado es 400 (regla de negocio del service, mensaje "El lugar seleccionado ya está registrado en el punto de partida {NOMBRE}"). El googlePlaceId se deja SIN regla unique a propósito: un 422 cortaría antes y el cliente nunca vería el nombre del punto que ya ocupa ese lugar, que es justo lo que hace útil el error.

    ATENCIÓN — LA UNICIDAD ES POR TABLA Y NO SE CRUZA CON locations. Ni el name ni el googlePlaceId se comparan contra los destinos: el mismo nombre y el mismo lugar de Google pueden estar dados de alta a la vez como destino y como punto de partida, y el alta responde 201 sin ningún aviso. Es intencionado —un mismo almacén puede ser origen de unos viajes y destino de otros—, pero significa que un duplicado creado por equivocarse de endpoint no lo detecta nadie.

    ATENCIÓN — el name se NORMALIZA antes de validarse y antes de guardarse: se recorta, se colapsan los espacios internos y se pasa a mayúsculas. Enviar "bodega central" crea el punto "BODEGA CENTRAL". Como la normalización ocurre ANTES de la regla de unicidad, enviar "bodega central" existiendo ya "BODEGA CENTRAL" devuelve 422, no 500 ni una fila duplicada. El googlePlaceId, en cambio, NO se toca: es opaco y sensible a mayúsculas, y pasarlo a mayúsculas apuntaría a otro lugar.

    ATENCIÓN — LA API NUNCA LLAMA A GOOGLE. El front busca la dirección en GET /api/places, deja que el usuario elija y manda aquí el googlePlaceId y las coordenadas ya resueltos. No se comprueba que el place id exista, ni HAY VALIDACIÓN CRUZADA entre él y las coordenadas: se pueden dar de alta unas coordenadas de un sitio con el place id de otro y el alta responde 201 sin ningún aviso.
    TEXT,
    required: ['name', 'googlePlaceId', 'latitude', 'longitude'],
    properties: [
        new OA\Property(
            property: 'name',
            description: 'Nombre del punto de partida. Se guarda normalizado y en mayúsculas, así que se puede enviar en minúsculas. Debe ser único ENTRE PUNTOS DE PARTIDA, comparado ya normalizado: la unicidad es insensible a mayúsculas, pero NO se cruza con la tabla de destinos, donde el mismo nombre puede convivir. Ausente, vacío, no textual o de más de 255 caracteres devuelve 422 (mensajes: El nombre del punto de partida es obligatorio / El nombre del punto de partida debe ser texto / El nombre del punto de partida no puede superar los 255 caracteres / Ya existe un punto de partida con ese nombre). El límite de 255 es el de la columna, no una regla de negocio.',
            type: 'string',
            maxLength: 255,
            example: 'bodega central escuintla',
        ),
        new OA\Property(
            property: 'description',
            description: 'Descripción libre del punto de partida: referencias de acceso, portón de carga, horarios. ÚNICO CAMPO OPCIONAL y nullable: omitirlo o enviar null guarda null. No tiene longitud máxima —la columna es text—; solo se valida que sea texto (mensaje: La descripción debe ser texto). No se indexa ni participa en el filtro search del listado.',
            type: 'string',
            nullable: true,
            example: 'Entrada por el km 58, portón de carga 2',
        ),
        new OA\Property(
            property: 'googlePlaceId',
            description: 'Place id del lugar en Google Places, obtenido de GET /api/places. OBLIGATORIO. Se guarda TAL CUAL LLEGA: no se recorta ni se pasa a mayúsculas, y es SENSIBLE A MAYÚSCULAS, así que dos cadenas que solo difieran en el case son dos lugares distintos y ambas se aceptarían. Ausente, no textual o de más de 255 caracteres devuelve 422 (mensajes: El lugar de Google es obligatorio / El lugar de Google debe ser texto / El lugar de Google no puede superar los 255 caracteres). ATENCIÓN — si otro PUNTO DE PARTIDA ya lo usa, la respuesta es 400 y NO 422, con el mensaje "El lugar seleccionado ya está registrado en el punto de partida {NOMBRE}", que nombra al ocupante para que el usuario sepa dónde mirar; si quien lo usa es un DESTINO, no pasa nada: la comprobación mira solo esta tabla y el alta responde 201. No se valida contra Google: un place id inventado con el formato correcto se acepta.',
            type: 'string',
            maxLength: 255,
            example: 'ChIJd8BlQ2BZwokRAFUEcm_qrcA',
        ),
        new OA\Property(
            property: 'latitude',
            description: 'Latitud del punto de partida en grados decimales. OBLIGATORIA, numérica y en [-90, 90]; fuera de ese rango, ausente o no numérica devuelve 422 (mensajes: La latitud es obligatoria / La latitud debe ser numérica / La latitud debe estar entre -90 y 90). Se guarda con OCHO decimales y VUELVE COMO CADENA en el recurso. No se cruza con el googlePlaceId y no alimenta ningún cálculo: de ella no se deriva distancia, kilometraje, ruta ni precio.',
            type: 'number',
            format: 'float',
            maximum: 90,
            minimum: -90,
            example: 14.6349,
        ),
        new OA\Property(
            property: 'longitude',
            description: 'Longitud del punto de partida en grados decimales. OBLIGATORIA, numérica y en [-180, 180]; fuera de ese rango, ausente o no numérica devuelve 422 (mensajes: La longitud es obligatoria / La longitud debe ser numérica / La longitud debe estar entre -180 y 180). Se guarda con OCHO decimales y vuelve como cadena. A diferencia del area de Zones, aquí no hay pares ni orden ambiguo: latitude y longitude son dos campos con nombre propio, así que no hay forma de invertirlos por descuido.',
            type: 'number',
            format: 'float',
            maximum: 180,
            minimum: -180,
            example: -90.5069,
        ),
    ],
    type: 'object',
)]
class StoreDeparturePointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name before the rules run.
     *
     * Without it "bodega central" would pass the unique rule while BODEGA CENTRAL
     * exists and blow up against the unique index with a 500 instead of a 422.
     *
     * The google place id is deliberately left untouched: it is an opaque, case
     * sensitive identifier and upper casing it would point at a different place.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => DeparturePoint::normalizeName($this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * status y registeredBy no se aceptan: el punto nace activo y la autoría sale del
         * usuario autenticado. Las coordenadas se validan por rango, no contra el lugar de
         * Google: no hay validación cruzada entre el googlePlaceId y el pin.
         */
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('departure_points', 'name')],
            'description' => ['nullable', 'string'],
            /**
             * Sin regla unique a propósito: la unicidad del lugar la decide el service, que
             * responde 400 nombrando al punto que ya lo ocupa. Un 422 aquí cortaría antes y
             * el cliente nunca vería ese nombre, que es justo lo que hace útil el error.
             * La unicidad se comprueba solo en esta tabla: el mismo lugar puede estar dado
             * de alta como destino y no es conflicto.
             */
            'googlePlaceId' => ['required', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del punto de partida es obligatorio',
            'name.string' => 'El nombre del punto de partida debe ser texto',
            'name.max' => 'El nombre del punto de partida no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe un punto de partida con ese nombre',
            'description.string' => 'La descripción debe ser texto',
            'googlePlaceId.required' => 'El lugar de Google es obligatorio',
            'googlePlaceId.string' => 'El lugar de Google debe ser texto',
            'googlePlaceId.max' => 'El lugar de Google no puede superar los 255 caracteres',
            'latitude.required' => 'La latitud es obligatoria',
            'latitude.numeric' => 'La latitud debe ser numérica',
            'latitude.between' => 'La latitud debe estar entre -90 y 90',
            'longitude.required' => 'La longitud es obligatoria',
            'longitude.numeric' => 'La longitud debe ser numérica',
            'longitude.between' => 'La longitud debe estar entre -180 y 180',
        ];
    }
}
