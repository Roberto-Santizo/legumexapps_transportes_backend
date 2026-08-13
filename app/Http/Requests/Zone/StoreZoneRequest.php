<?php

namespace App\Http\Requests\Zone;

use App\Models\Zone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreZoneRequest',
    title: 'Alta de zona',
    description: <<<'TEXT'
    Cuerpo JSON para dar de alta una zona geográfica nacional. Los campos aceptados son name, description, color y area; solo name y area son obligatorios.

    El status NO se acepta: la zona nace siempre publicada (true) y enviarlo se descarta sin error, así que no hay forma de crear una zona ya dada de baja. El registeredBy tampoco se envía: se resuelve desde el usuario autenticado, que por el middleware role:administrator es siempre un administrador; mandarlo en el cuerpo no cambia nada.

    ATENCIÓN — el name se NORMALIZA antes de validarse y antes de guardarse: se recorta, se colapsan los espacios internos y se pasa a mayúsculas. Enviar "zona norte" crea la zona "ZONA NORTE". Como la normalización ocurre ANTES de la regla de unicidad, enviar "zona norte" existiendo ya "ZONA NORTE" devuelve 422, no 500 ni una fila duplicada. El color se pasa también a mayúsculas antes de validarse.

    ATENCIÓN — el area se manda como pares [latitud, longitud] con el ANILLO ABIERTO: se envían los 3 o más vértices distintos y NO se repite el primero al final; el cierre lo pone el servidor. El orden es latitud primero, al revés que GeoJSON y Leaflet.
    TEXT,
    required: ['name', 'area'],
    properties: [
        new OA\Property(
            property: 'name',
            description: 'Nombre de la zona. Se guarda normalizado y en mayúsculas, así que se puede enviar en minúsculas. Debe ser único en todo el país, comparado ya normalizado: la unicidad es global e insensible a mayúsculas. Vacío, ausente o de más de 255 caracteres devuelve 422 (mensajes: El nombre de la zona es obligatorio / El nombre de la zona debe ser texto / El nombre de la zona no puede superar los 255 caracteres / Ya existe una zona con ese nombre). El límite de 255 es el de la columna, no una regla de negocio.',
            type: 'string',
            maxLength: 255,
            example: 'zona norte',
        ),
        new OA\Property(
            property: 'description',
            description: 'Descripción libre de la zona. Opcional y nullable: omitirla o enviar null guarda null. Más de 1000 caracteres devuelve 422 (mensajes: La descripción debe ser texto / La descripción no puede superar los 1000 caracteres).',
            type: 'string',
            maxLength: 1000,
            nullable: true,
            example: 'Cobertura del norte del área metropolitana',
        ),
        new OA\Property(
            property: 'color',
            description: 'Color de la zona en el mapa, en hexadecimal #RRGGBB. OPCIONAL: si se omite o llega null, la zona nace con #3388FF, el azul por defecto de Leaflet, y el recurso nunca devuelve el color en null. Se normaliza a mayúsculas antes de validarse, así que "#ff0000" se guarda y se devuelve como "#FF0000". Solo se admite el formato de 6 dígitos con almohadilla: "rojo", "#FFF" o "FF0000" devuelven 422 con el mensaje: El color debe ser hexadecimal con el formato #RRGGBB.',
            type: 'string',
            pattern: '^#[0-9A-Fa-f]{6}$',
            example: '#ff0000',
        ),
        new OA\Property(
            property: 'area',
            description: <<<'TEXT'
            Polígono de la zona como lista de pares [latitud, longitud] con el ANILLO ABIERTO. Obligatorio, con un MÍNIMO DE 3 pares distintos y cada par de exactamente 2 números. NO se envía el primer punto repetido al final: el cierre del anillo lo añade el servidor. No hay tope máximo de vértices.

            ATENCIÓN — el orden dentro del par es LATITUD PRIMERO y longitud después, al revés que GeoJSON, Mapbox, turf y la forma [lng, lat] de Leaflet. La latitud debe estar en [-90, 90] y la longitud en [-180, 180], y son esas dos reglas las que atrapan el par invertido: mandar [-90.5069, 14.6349] devuelve 422 con "La latitud del punto 1 debe estar entre -90 y 90". Pero un par ambiguo, con ambos valores dentro de [-90, 90], pasa la validación y guarda la zona en el lugar equivocado sin ningún error.

            Otros 422 posibles: El área de la zona es obligatoria / El área debe ser una lista de puntos / El área debe tener al menos 3 puntos / El punto N debe tener exactamente 2 coordenadas: latitud y longitud / La latitud del punto N debe ser numérica / La longitud del punto N debe estar entre -180 y 180.

            No se valida el solape con otras zonas, ni que el polígono no se auto-intersecte: una figura en "8" se acepta. Tampoco se admiten agujeros ni multipolígonos: un solo anillo exterior.
            TEXT,
            type: 'array',
            items: new OA\Items(
                description: 'Par [latitud, longitud]: exactamente dos números, la latitud primero.',
                type: 'array',
                items: new OA\Items(type: 'number', format: 'float'),
                maxItems: 2,
                minItems: 2,
            ),
            minItems: 3,
            example: [[14.6349, -90.5069], [14.6402, -90.4998], [14.6281, -90.4931]],
        ),
    ],
    type: 'object',
)]
class StoreZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name and the colour before the rules run.
     *
     * Without the first step "zona norte" would pass the unique rule while ZONA NORTE
     * exists and blow up against the unique index with a 500 instead of a 422.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Zone::normalizeName($this->input('name'))]);
        }

        if (is_string($this->input('color'))) {
            $this->merge(['color' => mb_strtoupper(trim($this->input('color')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * status y registeredBy no se aceptan: la zona nace publicada y la autoría sale del
         * usuario autenticado. Las reglas anidadas son las que fijan el orden [lat, lng]: el
         * primer elemento del par se valida contra el rango de latitud y el segundo contra el
         * de longitud, así que invertir el par falla en vez de guardar la zona en otro sitio.
         */
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('zones', 'name')],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'area' => ['required', 'array', 'min:3'],
            'area.*' => ['required', 'array', 'size:2'],
            'area.*.0' => ['required', 'numeric', 'between:-90,90'],
            'area.*.1' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la zona es obligatorio',
            'name.string' => 'El nombre de la zona debe ser texto',
            'name.max' => 'El nombre de la zona no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe una zona con ese nombre',
            'description.string' => 'La descripción debe ser texto',
            'description.max' => 'La descripción no puede superar los 1000 caracteres',
            'color.string' => 'El color debe ser texto',
            'color.regex' => 'El color debe ser hexadecimal con el formato #RRGGBB',
            'area.required' => 'El área de la zona es obligatoria',
            'area.array' => 'El área debe ser una lista de puntos',
            'area.min' => 'El área debe tener al menos 3 puntos',
            'area.*.required' => 'El punto :position es obligatorio',
            'area.*.array' => 'El punto :position debe ser un par de coordenadas',
            'area.*.size' => 'El punto :position debe tener exactamente 2 coordenadas: latitud y longitud',
            'area.*.0.required' => 'La latitud del punto :position es obligatoria',
            'area.*.0.numeric' => 'La latitud del punto :position debe ser numérica',
            'area.*.0.between' => 'La latitud del punto :position debe estar entre -90 y 90',
            'area.*.1.required' => 'La longitud del punto :position es obligatoria',
            'area.*.1.numeric' => 'La longitud del punto :position debe ser numérica',
            'area.*.1.between' => 'La longitud del punto :position debe estar entre -180 y 180',
        ];
    }
}
