<?php

namespace App\Http\Requests\Location;

use App\Enums\LocationType;
use App\Models\Location;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateLocationRequest',
    title: 'Actualización de destino',
    description: <<<'TEXT'
    Cuerpo JSON para actualizar un destino. TODOS los campos son opcionales por separado y solo se toca lo que venga: un PATCH que solo manda description no altera el nombre, el lugar ni las coordenadas. Un CUERPO VACÍO responde 200 como no-op, devolviendo el destino sin cambios, NO 422.

    A diferencia del alta, aquí SÍ se acepta status: con status false se da de baja el destino —igual que el DELETE— y con status true se reactiva, lo mismo que hace PATCH /api/locations/{location}/toggle-status. El registeredBy sigue sin aceptarse y NO se reescribe: el destino conserva a quien lo dio de alta aunque lo edite otro administrador.

    ATENCIÓN — MISMA ASIMETRÍA 422/400 QUE EN EL ALTA. El name duplicado es 422 (regla unique, "Ya existe un destino con ese nombre"); el googlePlaceId duplicado es 400 (regla del service, "El lugar seleccionado ya está registrado en el destino {NOMBRE}"). Las dos reglas ignoran la propia fila, así que reenviar el mismo nombre o el mismo lugar del destino que se está editando responde 200 y no choca consigo mismo.

    ATENCIÓN — EL googlePlaceId ES EDITABLE Y ESO ES UN RIESGO ASUMIDO. Reapuntar el destino a otro lugar conserva la fila, su id y TODAS SUS TARIFAS de flete, que es justo el motivo de permitirlo: corregir un lugar mal capturado sin perder el historial de precios. Pero NO HAY VALIDACIÓN CRUZADA con las coordenadas: cambiar el googlePlaceId sin tocar latitude ni longitude es válido, responde 200 y deja el pin apuntando al lugar anterior, SIN NINGÚN AVISO. Si se reapunta el lugar, hay que mandar también las coordenadas nuevas en el mismo PATCH.

    ATENCIÓN — EL type SE CAMBIA SIN NINGUNA RESTRICCIÓN. Un destino con tarifas de flete colgando puede pasar a port y volver a destination, y la respuesta es 200 sin aviso: las tarifas quedan intactas y siguen cotizando igual. El tipo es una etiqueta de catálogo y no gobierna ningún precio, así que bloquear el cambio protegería de un riesgo que no existe e impediría corregir una etiqueta mal capturada. El valor anterior se pisa SIN BITÁCORA.

    El name se NORMALIZA igual que en el alta —recorte, colapso de espacios y mayúsculas—; el googlePlaceId no se toca. Las coordenadas se pueden corregir sueltas y NO influyen en el precio de ninguna cotización.
    TEXT,
    properties: [
        new OA\Property(
            property: 'name',
            description: 'Nuevo nombre del destino. Se normaliza a mayúsculas antes de validarse y de guardarse. La unicidad global se revalida IGNORANDO la propia fila, así que reenviar su mismo nombre responde 200 y usar el de otro destino responde 422 con "Ya existe un destino con ese nombre". No textual o de más de 255 caracteres es también 422 (El nombre del destino debe ser texto / El nombre del destino no puede superar los 255 caracteres). Omitir la clave deja el nombre intacto; enviar null NO lo borra, es 422 (el campo no es nullable).',
            type: 'string',
            maxLength: 255,
            example: 'bodega central escuintla',
        ),
        new OA\Property(
            property: 'description',
            description: 'Nueva descripción. Es el ÚNICO campo que acepta null, y ese null BORRA la descripción —se distingue de omitir la clave, que la deja como está—. Sin longitud máxima; solo se valida que sea texto (mensaje: La descripción debe ser texto).',
            type: 'string',
            nullable: true,
            example: 'Entrada por el km 58, portón de carga 2',
        ),
        new OA\Property(
            property: 'type',
            description: 'Nuevo tipo de destino: port o destination. Semántica parcial de siempre: omitir la clave deja el tipo intacto y enviarla obliga a un valor del enum, así que "" o null son 422 (mensaje: El tipo de destino no es válido). La validación es EXACTA y SENSIBLE A MAYÚSCULAS: "PORT" o "puerto" también son 422. SIN NINGUNA RESTRICCIÓN: un destino que YA TIENE TARIFAS de flete puede pasar a port y volver, y la respuesta es 200 sin aviso; las tarifas quedan intactas y siguen cotizando igual, porque el tipo no gobierna ningún precio. Pisar el valor anterior NO deja rastro: no hay bitácora del cambio de tipo.',
            type: 'string',
            enum: ['port', 'destination'],
            example: 'port',
        ),
        new OA\Property(
            property: 'googlePlaceId',
            description: 'Nuevo place id de Google. Se guarda tal cual llega, sensible a mayúsculas, sin normalizar y sin comprobarse contra Google. Si otro destino ya lo usa, la respuesta es 400 —no 422— con "El lugar seleccionado ya está registrado en el destino {NOMBRE}"; reenviar el propio del destino que se edita es 200, porque la comprobación ignora su propia fila. ATENCIÓN — cambiarlo REAPUNTA el destino conservando id y tarifas, y NO arrastra las coordenadas: latitude y longitude siguen donde estaban hasta que se manden explícitamente, sin aviso ninguno de que el pin quedó desalineado.',
            type: 'string',
            maxLength: 255,
            example: 'ChIJd8BlQ2BZwokRAFUEcm_qrcA',
        ),
        new OA\Property(
            property: 'latitude',
            description: 'Nueva latitud en grados decimales, numérica y en [-90, 90]; fuera de rango o no numérica es 422 (La latitud debe ser numérica / La latitud debe estar entre -90 y 90). Se puede corregir SOLA, sin tocar el googlePlaceId ni la longitud: no hay validación cruzada de ningún tipo. Corregirla NO cambia ninguna cotización, porque el precio depende del destino elegido y no de dónde esté el pin.',
            type: 'number',
            format: 'float',
            maximum: 90,
            minimum: -90,
            example: 14.6349,
        ),
        new OA\Property(
            property: 'longitude',
            description: 'Nueva longitud en grados decimales, numérica y en [-180, 180]; fuera de rango o no numérica es 422 (La longitud debe ser numérica / La longitud debe estar entre -180 y 180). Igual que latitude: se corrige suelta, sin cruces y sin efecto en los precios.',
            type: 'number',
            format: 'float',
            maximum: 180,
            minimum: -180,
            example: -90.5069,
        ),
        new OA\Property(
            property: 'status',
            description: 'Publicación del destino. A diferencia del alta, aquí SÍ se acepta: con false se da de baja —exactamente igual que el DELETE— y con true se reactiva, igual que el toggle. Debe ser un booleano; cualquier otra cosa es 422 con "El estado debe ser verdadero o falso". El solapamiento con PATCH /api/locations/{location}/toggle-status es deliberado: el toggle sirve al interruptor de una tabla y este campo al formulario de edición, que manda estado y datos juntos. Desactivar un destino NO borra sus tarifas, pero impide cotizarlas y congela su edición.',
            type: 'boolean',
            example: false,
        ),
    ],
    type: 'object',
)]
class UpdateLocationRequest extends FormRequest
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
         * Todos los campos son opcionales por separado y un PATCH con el cuerpo vacío se
         * acepta como no-op. El ignore() del propio id permite reenviar el mismo nombre o el
         * mismo lugar sin chocar consigo mismo. Las coordenadas se pueden corregir sueltas:
         * no se validan contra el googlePlaceId, que puede reapuntarse sin tocarlas.
         */
        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('locations', 'name')->ignore($this->route('location'))],
            'description' => ['sometimes', 'nullable', 'string'],
            /** Sin guarda de negocio detrás: el tipo se cambia aunque el destino ya tenga tarifas. */
            'type' => ['sometimes', 'required', Rule::enum(LocationType::class)],
            /**
             * Sin regla unique a propósito: la unicidad del lugar la decide el service, que
             * responde 400 nombrando al destino que ya lo ocupa e ignora la propia fila, así
             * que reenviar el mismo googlePlaceId sigue siendo un 200.
             */
            'googlePlaceId' => ['sometimes', 'string', 'max:255'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'status' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.string' => 'El nombre del destino debe ser texto',
            'name.max' => 'El nombre del destino no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe un destino con ese nombre',
            'description.string' => 'La descripción debe ser texto',
            'type.required' => 'El tipo de destino es obligatorio',
            'type.enum' => 'El tipo de destino no es válido',
            'googlePlaceId.string' => 'El lugar de Google debe ser texto',
            'googlePlaceId.max' => 'El lugar de Google no puede superar los 255 caracteres',
            'latitude.numeric' => 'La latitud debe ser numérica',
            'latitude.between' => 'La latitud debe estar entre -90 y 90',
            'longitude.numeric' => 'La longitud debe ser numérica',
            'longitude.between' => 'La longitud debe estar entre -180 y 180',
            'status.boolean' => 'El estado debe ser verdadero o falso',
        ];
    }
}
