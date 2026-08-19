<?php

namespace App\Http\Requests\VehicleExpense;

use App\Enums\VehicleExpenseCategory;
use App\Enums\VehicleExpenseNature;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateVehicleExpenseRequest',
    title: 'Edición de gasto de vehículo',
    description: <<<'TEXT'
    Cuerpo JSON para editar un gasto. Es una edición PARCIAL: los CINCO campos son opcionales y solo se actualiza lo que se envía; lo omitido queda intacto. Un CUERPO VACÍO es válido y responde 200 sin tocar nada, ni siquiera updated_at. Ningún campo acepta null: si se envía, debe traer un valor válido.

    ATENCIÓN — vehicle_id NO APARECE AQUÍ Y NO SE PUEDE CAMBIAR. El vehículo se fija en el alta y es inmutable: mandar vehicle_id (o vehicleId) en este cuerpo NO ES UN ERROR —no devuelve 422 ni 400—, simplemente SE IGNORA en silencio y el gasto sigue colgando del mismo vehículo. Es la trampa principal de este endpoint: la petición responde 200 y parece que funcionó. Mover un gasto de vehículo es borrarlo y volverlo a crear.

    registered_by tampoco se acepta y tampoco se reescribe: el gasto conserva PARA SIEMPRE al usuario que lo creó, aunque quien edite sea un administrator. En la respuesta, registeredBy seguirá siendo el nombre original.

    El cuerpo va en snake_case (expense_date) y la respuesta vuelve en camelCase (expenseDate). No hay bitácora de ediciones: el PATCH no deja rastro consultable.
    TEXT,
    properties: [
        new OA\Property(
            property: 'category',
            description: 'Nueva categoría del gasto. Si se envía debe ser uno de los 22 valores del enum; cualquier otro devuelve 422 con el mensaje La categoría del gasto no es válida. Se puede cambiar sin tocar nature: los dos ejes son independientes y no hay validación cruzada.',
            type: 'string',
            enum: [
                'tires', 'oil_change', 'brakes', 'spare_part', 'battery', 'suspension',
                'engine', 'transmission', 'electrical_system', 'cooling_system', 'filters',
                'alignment_balancing', 'clutch', 'exhaust', 'air_conditioning', 'bodywork_paint',
                'glass_mirrors', 'inspection', 'washing', 'towing', 'labor', 'other',
            ],
            example: 'clutch',
        ),
        new OA\Property(
            property: 'nature',
            description: 'Nueva naturaleza del gasto. Si se envía debe ser preventive o corrective; cualquier otro valor devuelve 422 con el mensaje La naturaleza del gasto no es válida. Reclasificar un gasto de preventive a corrective no exige cambiar también la categoría.',
            type: 'string',
            enum: ['preventive', 'corrective'],
            example: 'corrective',
        ),
        new OA\Property(
            property: 'amount',
            description: 'Nuevo monto EN QUETZALES (GTQ). Rigen las mismas cotas que en el alta: numérico, mínimo 0.01 —un 0 devuelve 422— y máximo 99999999.99. Cambiar el monto altera el totalAmount que devuelve el listado del vehículo, y no queda registro del valor anterior.',
            type: 'number',
            format: 'float',
            maximum: 99999999.99,
            minimum: 0.01,
            example: 4321.1,
        ),
        new OA\Property(
            property: 'expense_date',
            description: 'Nueva fecha del gasto, en formato Y-m-d y SIN FECHAS FUTURAS (before_or_equal:today): una fecha de mañana devuelve 422 con el mensaje La fecha del gasto no puede ser futura. Cambiarla mueve el gasto de sitio en el listado, que ordena por expense_date descendente, y puede sacarlo del rango dateFrom/dateTo con el que se estaba consultando.',
            type: 'string',
            format: 'date',
            example: '2026-06-30',
        ),
        new OA\Property(
            property: 'description',
            description: 'Nueva descripción, de hasta 1000 caracteres. Sustituye por completo a la anterior: no se concatena ni se versiona, y el texto previo se pierde sin rastro.',
            type: 'string',
            maxLength: 1000,
            example: 'Embrague nuevo, taller El Rodaje',
        ),
    ],
    type: 'object',
)]
class UpdateVehicleExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Every field is optional, but none of them accepts null once sent.
     *
     * `vehicle_id` is absent on purpose: the vehicle is fixed when the expense
     * is registered, so moving an expense means deleting it and creating it
     * again. Sending it is not an error — it is simply ignored, like any other
     * unknown field. An empty body is a valid no-op that answers 200.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['sometimes', Rule::enum(VehicleExpenseCategory::class)],
            'nature' => ['sometimes', Rule::enum(VehicleExpenseNature::class)],
            'amount' => ['sometimes', 'numeric', 'min:0.01', 'max:99999999.99'],
            'expense_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'description' => ['sometimes', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.enum' => 'La categoría del gasto no es válida',
            'nature.enum' => 'La naturaleza del gasto no es válida',
            'amount.numeric' => 'El monto debe ser un número',
            'amount.min' => 'El monto debe ser mayor que cero',
            'amount.max' => 'El monto no puede superar los 99999999.99',
            'expense_date.date' => 'La fecha del gasto no es válida',
            'expense_date.before_or_equal' => 'La fecha del gasto no puede ser futura',
            'description.string' => 'La descripción debe ser texto',
            'description.max' => 'La descripción no puede superar los 1000 caracteres',
        ];
    }
}
