<?php

namespace App\Http\Requests\Zone;

use App\Models\Zone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateZoneRequest',
    title: 'Actualización de zona',
    description: <<<'TEXT'
    Cuerpo JSON para actualizar una zona. Acepta name, description, color, status y area, TODOS opcionales por separado, y solo se toca lo que venga: enviar únicamente el name no altera el área, ni el color, ni la descripción.

    ATENCIÓN — a diferencia de Products, aquí NO hay regla required_without cruzada: un PATCH con el CUERPO VACÍO responde 200 como no-op, sin cambiar nada y sin 422. Con cinco campos editables, encadenar required_without produciría cinco mensajes idénticos en un 422 confuso.

    El name se normaliza —recorte, colapso de espacios internos y mayúsculas— antes de validarse y antes de guardarse, igual que en el alta. La comprobación de unicidad ignora la propia fila, así que reenviar su mismo nombre responde 200 y no 422; reenviar el de otra zona sí devuelve 422. El color se pasa también a mayúsculas antes de validarse.

    El area, si viene, SUSTITUYE al polígono anterior ENTERO y sin dejar rastro del previo: no hay edición de vértices sueltos, para mover un punto se manda el array completo con las mismas reglas del alta.

    El registeredBy no se puede cambiar y el PATCH no lo reescribe: sigue apuntando a quien dio de alta la zona.
    TEXT,
    properties: [
        new OA\Property(
            property: 'name',
            description: 'Nuevo nombre de la zona. Se guarda normalizado y en mayúsculas, debe seguir siendo único a nivel nacional —ignorando esta misma zona— y no puede superar los 255 caracteres (mensajes: El nombre de la zona debe ser texto / El nombre de la zona no puede superar los 255 caracteres / Ya existe una zona con ese nombre).',
            type: 'string',
            maxLength: 255,
            example: 'zona norte',
        ),
        new OA\Property(
            property: 'description',
            description: 'Nueva descripción, hasta 1000 caracteres. Es el único campo que acepta null explícito: enviar null BORRA la descripción, mientras que omitir la clave la deja como estaba.',
            type: 'string',
            maxLength: 1000,
            nullable: true,
            example: 'Cobertura ampliada hasta el kilómetro 25',
        ),
        new OA\Property(
            property: 'color',
            description: 'Nuevo color en hexadecimal #RRGGBB, normalizado a mayúsculas. ATENCIÓN — al contrario que description, aquí null NO es válido: enviar color: null devuelve 422 con "El color debe ser texto". Para dejar el color como está, se omite la clave; no hay forma de volver al azul por defecto salvo enviando #3388FF explícitamente. Un valor que no case con el formato —"rojo", "#FFF"— devuelve 422 con: El color debe ser hexadecimal con el formato #RRGGBB.',
            type: 'string',
            pattern: '^#[0-9A-Fa-f]{6}$',
            example: '#123456',
        ),
        new OA\Property(
            property: 'status',
            description: 'Nueva publicación de la zona, como BOOLEANO. Con false se da de baja y con true se reactiva, lo mismo que consiguen DELETE /api/zones/{zone} y PATCH /api/zones/{zone}/toggle-status; el solapamiento es deliberado: el toggle sirve al interruptor de una tabla y este campo al formulario de edición que manda estado y datos juntos. Un valor no booleano devuelve 422 con el mensaje: El estado debe ser verdadero o falso.',
            type: 'boolean',
            example: false,
        ),
        new OA\Property(
            property: 'area',
            description: <<<'TEXT'
            Nuevo polígono como lista de pares [latitud, longitud] con el ANILLO ABIERTO, mínimo 3 pares y cada par de exactamente 2 números. Si se envía, REEMPLAZA el polígono completo; si se omite, el área anterior no se toca. No se guarda el trazado previo.

            ATENCIÓN — mismas reglas y mismo riesgo que en el alta: la LATITUD VA PRIMERO, en [-90, 90], y la longitud después, en [-180, 180], al revés que GeoJSON y Leaflet. Un par invertido cuya longitud caiga fuera de [-90, 90] devuelve 422 con "La latitud del punto N debe estar entre -90 y 90"; un par ambiguo con ambos valores en rango se guarda mal sin ningún error.
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
class UpdateZoneRequest extends FormRequest
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
         * Todos los campos son opcionales por separado y no hay required_without cruzado: con
         * cinco campos editables encadenarlos produciría cinco mensajes idénticos en un 422.
         * Un PATCH con el cuerpo vacío se acepta como no-op. El ignore() del propio id permite
         * reenviar el mismo nombre sin chocar consigo mismo, y el área que llegue sustituye al
         * polígono anterior entero.
         */
        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('zones', 'name')->ignore($this->route('zone'))],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'status' => ['sometimes', 'boolean'],
            'area' => ['sometimes', 'array', 'min:3'],
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
            'name.string' => 'El nombre de la zona debe ser texto',
            'name.max' => 'El nombre de la zona no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe una zona con ese nombre',
            'description.string' => 'La descripción debe ser texto',
            'description.max' => 'La descripción no puede superar los 1000 caracteres',
            'color.string' => 'El color debe ser texto',
            'color.regex' => 'El color debe ser hexadecimal con el formato #RRGGBB',
            'status.boolean' => 'El estado debe ser verdadero o falso',
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
