<?php

namespace App\Http\Requests\Accessory;

use App\Enums\AccessoryStatus;
use App\Models\Accessory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateAccessoryRequest',
    title: 'Actualización de accesorio',
    description: <<<'TEXT'
    Cuerpo JSON para actualizar un accesorio. TODOS los campos son opcionales por separado y solo se toca lo que venga: un PATCH que solo manda description no altera el nombre, el código, el precio ni la fecha de compra. Un CUERPO VACÍO responde 200 como no-op, devolviendo el accesorio sin cambios, NO 422.

    A diferencia del alta, aquí SÍ se acepta status, y es la ÚNICA vía para moverlo: no existe /toggle-status en este dominio —con tres estados un interruptor no significa nada—. El status se mueve LIBREMENTE entre "active", "inactive" y "under_repair", SIN reglas de transición y sin comprobar el estado anterior: de "inactive" se puede saltar a "under_repair" sin pasar por "active", y reenviar el mismo estado es 200 y no-op. Con status "inactive" se da de baja el accesorio, igual que el DELETE, y con "active" se reactiva.

    El registeredBy sigue sin aceptarse y NO se reescribe: el accesorio conserva a quien lo dio de alta aunque lo edite otro administrador. El currentValue tampoco se acepta: es DERIVADO y de SOLO SALIDA, y mandarlo se IGNORA EN SILENCIO. Eso sí, editar price, purchaseDate o annualDepreciation CAMBIA el currentValue de la siguiente lectura, porque los tres son sus entradas.

    ATENCIÓN — name y code se NORMALIZAN igual que en el alta y CON REGLAS DISTINTAS ENTRE SÍ: el name colapsa los espacios internos, el code NO. Las dos reglas unique IGNORAN LA PROPIA FILA, así que reenviar su mismo nombre o su mismo código responde 200 y no choca consigo mismo; usar el de otro accesorio responde 422 ("Ya existe un accesorio con ese nombre" / "Ya existe un accesorio con ese código"). La unicidad del código sigue siendo GLOBAL Y CIEGA AL STATUS: el código de una unidad dada de baja no se puede reutilizar.

    ATENCIÓN — EL code ES EDITABLE: corregir un código mal tecleado conserva la fila, su id y su historial, que es justo el motivo de permitirlo. La description acepta null explícito para BORRARLA —se distingue de omitir la clave, que la deja como está—; ningún otro campo acepta null.
    TEXT,
    properties: [
        new OA\Property(
            property: 'name',
            description: 'Nuevo nombre del accesorio. Se normaliza a mayúsculas —con colapso de espacios internos— antes de validarse y de guardarse. La unicidad global se revalida IGNORANDO la propia fila, así que reenviar su mismo nombre responde 200 y usar el de otro accesorio responde 422 con "Ya existe un accesorio con ese nombre". No textual o de más de 255 caracteres es también 422 (El nombre del accesorio debe ser texto / El nombre del accesorio no puede superar los 255 caracteres). Omitir la clave deja el nombre intacto; enviar null NO lo borra, es 422 (el campo no es nullable).',
            type: 'string',
            maxLength: 255,
            example: 'gato hidráulico 3 toneladas',
        ),
        new OA\Property(
            property: 'code',
            description: 'Nuevo código interno o número de serie. ES EDITABLE a propósito: corregir un código mal tecleado conserva la fila, su id y su historial. Se recorta y se pasa a mayúsculas pero NO se le colapsan los espacios internos, así que cambiar "A100" por "A 100" es un cambio real y ambos pueden coexistir en la tabla. La unicidad se revalida ignorando la propia fila —reenviar su propio código es 200— y sigue siendo GLOBAL Y CIEGA AL STATUS: el código que ocupa un accesorio dado de baja NO se puede reutilizar, y pedirlo devuelve 422 con "Ya existe un accesorio con ese código". No textual o de más de 255 caracteres es también 422.',
            type: 'string',
            maxLength: 255,
            example: 'acc-0042',
        ),
        new OA\Property(
            property: 'description',
            description: 'Nueva descripción. Es el ÚNICO campo que acepta null, y ese null BORRA la descripción —se distingue de omitir la clave, que la deja como está—. Máximo 1000 caracteres y solo se valida que sea texto (mensajes: La descripción debe ser texto / La descripción no puede superar los 1000 caracteres). No participa en el filtro search del listado.',
            type: 'string',
            maxLength: 1000,
            nullable: true,
            example: 'Marca Truper, guardado en bodega central, estante 4',
        ),
        new OA\Property(
            property: 'price',
            description: 'Nuevo precio de compra, en GTQ, numérico y en [0.01, 99999999.99]; 0, no numérico o por encima del máximo es 422 (El precio debe ser mayor a 0 / El precio debe ser numérico / El precio no puede superar 99999999.99). ATENCIÓN — corregirlo CAMBIA el currentValue de la siguiente lectura, porque el valor depreciado se deriva de él; no hay ninguna bitácora del precio anterior, así que el valor viejo se pierde sin rastro.',
            type: 'number',
            format: 'float',
            maximum: 99999999.99,
            minimum: 0.01,
            example: 1250,
        ),
        new OA\Property(
            property: 'purchaseDate',
            description: 'Nueva fecha de compra, válida y NO FUTURA (mensajes: La fecha de compra debe ser una fecha válida / La fecha de compra no puede ser futura). Se envía en formato ISO (Y-m-d) y vuelve en d-m-Y. ATENCIÓN — es la entrada más sensible del currentValue: moverla hacia atrás envejece el accesorio y baja su valor, moverla hacia adelante lo rejuvenece y lo sube, todo sin tocar el price. No se cruza con createdAt: se puede fijar una compra muy anterior a la captura de la fila, que es el caso normal al inventariar material viejo.',
            type: 'string',
            format: 'date',
            example: '2024-03-15',
        ),
        new OA\Property(
            property: 'annualDepreciation',
            description: 'Nuevo porcentaje anual de depreciación lineal, numérico y en [0, 100] —los dos extremos se aceptan— (mensajes: El porcentaje de depreciación anual debe ser numérico / El porcentaje de depreciación anual no puede ser negativo / El porcentaje de depreciación anual no puede superar 100). Cambiarlo recalcula el currentValue de la siguiente lectura y NO es retroactivo en ningún sentido contable: no existe historial de tasas, solo la vigente. Ponerlo a 0 congela el valor en el price para siempre.',
            type: 'number',
            format: 'float',
            maximum: 100,
            minimum: 0,
            example: 20,
        ),
        new OA\Property(
            property: 'status',
            description: 'Nuevo estado del accesorio: uno de los TRES valores del enum —"active", "inactive" o "under_repair"—, como CADENA y nunca como booleano. Cualquier otro valor es 422 con "El estado debe ser active, inactive o under_repair". ATENCIÓN — este PATCH es la ÚNICA vía para cambiar el estado: NO existe /api/accessories/{accessory}/toggle-status, porque con tres valores no hay nada que invertir. No hay reglas de transición: cualquier estado puede pasar a cualquier otro, incluido a sí mismo, y reenviar el actual es 200 y no-op. Con "inactive" se da de baja igual que con el DELETE; con "active" se reactiva. Cambiar el estado NO libera el código del accesorio, que sigue ocupado en cualquiera de los tres.',
            type: 'string',
            enum: ['active', 'inactive', 'under_repair'],
            example: 'under_repair',
        ),
    ],
    type: 'object',
)]
class UpdateAccessoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name and the code before the rules run.
     *
     * Without it "gato hidráulico" would pass the unique rule while GATO HIDRÁULICO
     * exists and blow up against the unique index with a 500 instead of a 422.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Accessory::normalizeName($this->input('name'))]);
        }

        if (is_string($this->input('code'))) {
            $this->merge(['code' => Accessory::normalizeCode($this->input('code'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * Todos los campos son opcionales por separado y un PATCH con el cuerpo vacío se
         * acepta como no-op. El ignore() del propio id permite reenviar el mismo nombre o
         * el mismo código sin chocar consigo mismo. El status se mueve libremente entre
         * los tres valores: no hay reglas de transición. registeredBy no se acepta y
         * currentValue tampoco, que es derivado y de solo salida.
         */
        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('accessories', 'name')->ignore($this->route('accessory'))],
            'code' => ['sometimes', 'string', 'max:255', Rule::unique('accessories', 'code')->ignore($this->route('accessory'))],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'price' => ['sometimes', 'numeric', 'min:0.01', 'max:99999999.99'],
            'purchaseDate' => ['sometimes', 'date', 'before_or_equal:today'],
            'annualDepreciation' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'status' => ['sometimes', Rule::enum(AccessoryStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.string' => 'El nombre del accesorio debe ser texto',
            'name.max' => 'El nombre del accesorio no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe un accesorio con ese nombre',
            'code.string' => 'El código del accesorio debe ser texto',
            'code.max' => 'El código del accesorio no puede superar los 255 caracteres',
            'code.unique' => 'Ya existe un accesorio con ese código',
            'description.string' => 'La descripción debe ser texto',
            'description.max' => 'La descripción no puede superar los 1000 caracteres',
            'price.numeric' => 'El precio debe ser numérico',
            'price.min' => 'El precio debe ser mayor a 0',
            'price.max' => 'El precio no puede superar 99999999.99',
            'purchaseDate.date' => 'La fecha de compra debe ser una fecha válida',
            'purchaseDate.before_or_equal' => 'La fecha de compra no puede ser futura',
            'annualDepreciation.numeric' => 'El porcentaje de depreciación anual debe ser numérico',
            'annualDepreciation.min' => 'El porcentaje de depreciación anual no puede ser negativo',
            'annualDepreciation.max' => 'El porcentaje de depreciación anual no puede superar 100',
            'status.enum' => 'El estado debe ser active, inactive o under_repair',
        ];
    }
}
