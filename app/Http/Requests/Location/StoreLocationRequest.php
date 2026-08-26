<?php

namespace App\Http\Requests\Location;

use App\Enums\LocationType;
use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreLocationRequest',
    title: 'Alta de destino',
    description: <<<'TEXT'
    Cuerpo JSON para dar de alta un destino nacional. Los campos aceptados son name, description, type, googlePlaceId, latitude y longitude; todos son obligatorios salvo description.

    ATENCIÓN — CAMBIO INCOMPATIBLE: el campo type es OBLIGATORIO desde SPEC 21 y no tiene periodo de gracia. Un cliente que siga mandando los cuatro campos de antes recibe 422 con "El tipo de destino es obligatorio". El default destination de la columna existe para las filas ya migradas, no para que el alta pueda omitirlo.

    El status NO se acepta: el destino nace siempre activo (true) y enviarlo se descarta sin error, así que no hay forma de crear un destino ya dado de baja. El registeredBy tampoco se envía: se resuelve desde el usuario autenticado, que por el middleware role:administrator es siempre un administrador; mandarlo en el cuerpo no cambia nada.

    ATENCIÓN — ASIMETRÍA DELIBERADA ENTRE LOS DOS DUPLICADOS POSIBLES. El name duplicado es 422 (regla unique del FormRequest, mensaje "Ya existe un destino con ese nombre"); el googlePlaceId duplicado es 400 (regla de negocio del service, mensaje "El lugar seleccionado ya está registrado en el destino {NOMBRE}"). El googlePlaceId se deja SIN regla unique a propósito: un 422 cortaría antes y el cliente nunca vería el nombre del destino que ya ocupa ese lugar, que es justo lo que hace útil el error. La columna sí tiene índice único en base, pero es el último cortafuegos, no la vía por la que se responde.

    ATENCIÓN — el name se NORMALIZA antes de validarse y antes de guardarse: se recorta, se colapsan los espacios internos y se pasa a mayúsculas. Enviar "bodega central" crea el destino "BODEGA CENTRAL". Como la normalización ocurre ANTES de la regla de unicidad, enviar "bodega central" existiendo ya "BODEGA CENTRAL" devuelve 422, no 500 ni una fila duplicada. El googlePlaceId, en cambio, NO se toca: es opaco y sensible a mayúsculas, y pasarlo a mayúsculas apuntaría a otro lugar.

    ATENCIÓN — NO HAY VALIDACIÓN CRUZADA entre el googlePlaceId y las coordenadas: nadie comprueba contra Google que latitude y longitude correspondan al lugar. Se pueden dar de alta unas coordenadas de un sitio con el place id de otro y el alta responde 201 sin ningún aviso. Las coordenadas, además, NO INFLUYEN EN EL PRECIO: la tarifa depende del destino elegido, no de dónde esté.
    TEXT,
    required: ['name', 'type', 'googlePlaceId', 'latitude', 'longitude'],
    properties: [
        new OA\Property(
            property: 'name',
            description: 'Nombre del destino. Se guarda normalizado y en mayúsculas, así que se puede enviar en minúsculas. Debe ser único en todo el país, comparado ya normalizado: la unicidad es global e insensible a mayúsculas. Ausente, vacío, no textual o de más de 255 caracteres devuelve 422 (mensajes: El nombre del destino es obligatorio / El nombre del destino debe ser texto / El nombre del destino no puede superar los 255 caracteres / Ya existe un destino con ese nombre). El límite de 255 es el de la columna, no una regla de negocio.',
            type: 'string',
            maxLength: 255,
            example: 'bodega central escuintla',
        ),
        new OA\Property(
            property: 'description',
            description: 'Descripción libre del destino: referencias de acceso, portón de carga, horarios. ÚNICO CAMPO OPCIONAL y nullable: omitirlo o enviar null guarda null. No tiene longitud máxima —la columna es text—; solo se valida que sea texto (mensaje: La descripción debe ser texto). No se indexa ni participa en el filtro search del listado.',
            type: 'string',
            nullable: true,
            example: 'Entrada por el km 58, portón de carga 2',
        ),
        new OA\Property(
            property: 'type',
            description: 'Tipo de destino: port para un puerto y destination para un destino ordinario. OBLIGATORIO desde SPEC 21 —CAMBIO INCOMPATIBLE respecto al alta anterior— y validado EXACTAMENTE contra el enum: es SENSIBLE A MAYÚSCULAS y no acepta traducciones, así que "PORT", "Port" o "puerto" devuelven 422 igual que un valor inventado (mensajes: El tipo de destino es obligatorio / El tipo de destino no es válido). Es una ETIQUETA DE CATÁLOGO, no una regla de negocio: no cambia el precio, no aparece en GET /api/freight-rates/quote, no restringe qué tarifas se pueden crear y no altera el ámbito por rol. Tampoco se cruza con nada: un destino llamado "TERMINAL DE CARGA PUERTO QUETZAL" puede darse de alta como destination sin ningún aviso.',
            type: 'string',
            enum: ['port', 'destination'],
            example: 'destination',
        ),
        new OA\Property(
            property: 'googlePlaceId',
            description: 'Place id del lugar en Google Places, obtenido de GET /api/places. OBLIGATORIO. Se guarda TAL CUAL LLEGA: no se recorta ni se pasa a mayúsculas, y es SENSIBLE A MAYÚSCULAS, así que dos cadenas que solo difieran en el case son dos lugares distintos y ambas se aceptarían. Ausente, no textual o de más de 255 caracteres devuelve 422 (mensajes: El lugar de Google es obligatorio / El lugar de Google debe ser texto / El lugar de Google no puede superar los 255 caracteres). ATENCIÓN — si otro destino ya lo usa, la respuesta es 400 y NO 422, con el mensaje "El lugar seleccionado ya está registrado en el destino {NOMBRE}", que nombra al ocupante para que el usuario sepa dónde mirar. No se valida contra Google: un place id inventado con el formato correcto se acepta.',
            type: 'string',
            maxLength: 255,
            example: 'ChIJd8BlQ2BZwokRAFUEcm_qrcA',
        ),
        new OA\Property(
            property: 'latitude',
            description: 'Latitud del destino en grados decimales. OBLIGATORIA, numérica y en [-90, 90]; fuera de ese rango, ausente o no numérica devuelve 422 (mensajes: La latitud es obligatoria / La latitud debe ser numérica / La latitud debe estar entre -90 y 90). Se guarda con OCHO decimales y VUELVE COMO CADENA en el recurso. No se cruza con el googlePlaceId ni influye en el precio: no se deriva de ella distancia, kilometraje ni ruta.',
            type: 'number',
            format: 'float',
            maximum: 90,
            minimum: -90,
            example: 14.6349,
        ),
        new OA\Property(
            property: 'longitude',
            description: 'Longitud del destino en grados decimales. OBLIGATORIA, numérica y en [-180, 180]; fuera de ese rango, ausente o no numérica devuelve 422 (mensajes: La longitud es obligatoria / La longitud debe ser numérica / La longitud debe estar entre -180 y 180). Se guarda con OCHO decimales y vuelve como cadena. A diferencia del area de Zones, aquí no hay pares ni orden ambiguo: latitude y longitude son dos campos con nombre propio, así que no hay forma de invertirlos por descuido.',
            type: 'number',
            format: 'float',
            maximum: 180,
            minimum: -180,
            example: -90.5069,
        ),
    ],
    type: 'object',
)]
class StoreLocationRequest extends FormRequest
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
            $this->merge(['name' => Location::normalizeName($this->input('name'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * status y registeredBy no se aceptan: el destino nace activo y la autoría sale del
         * usuario autenticado. Las coordenadas se validan por rango, no contra el lugar de
         * Google: no hay validación cruzada entre el googlePlaceId y el pin.
         */
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('locations', 'name')],
            'description' => ['nullable', 'string'],
            /** Coincidencia exacta contra el enum: sensible a mayúsculas y sin traducciones. */
            'type' => ['required', Rule::enum(LocationType::class)],
            /**
             * Sin regla unique a propósito: la unicidad del lugar la decide el service, que
             * responde 400 nombrando al destino que ya lo ocupa. Un 422 aquí cortaría antes y
             * el cliente nunca vería ese nombre, que es justo lo que hace útil el error.
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
            'name.required' => 'El nombre del destino es obligatorio',
            'name.string' => 'El nombre del destino debe ser texto',
            'name.max' => 'El nombre del destino no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe un destino con ese nombre',
            'description.string' => 'La descripción debe ser texto',
            'type.required' => 'El tipo de destino es obligatorio',
            'type.enum' => 'El tipo de destino no es válido',
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
