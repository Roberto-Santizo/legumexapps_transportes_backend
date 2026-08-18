<?php

namespace App\Http\Requests\VehicleExpense;

use App\Enums\VehicleExpenseCategory;
use App\Enums\VehicleExpenseNature;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The six business fields are required, and none of them accepts null.
     *
     * `vehicle_id` deliberately carries no `exists` rule: a vehicle that does
     * not exist is resolved by the service and answers 404, not 422, and one
     * belonging to another company answers 403.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'integer'],
            'category' => ['required', Rule::enum(VehicleExpenseCategory::class)],
            'nature' => ['required', Rule::enum(VehicleExpenseNature::class)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'El vehículo es obligatorio',
            'vehicle_id.integer' => 'El vehículo debe ser un número entero',
            'category.required' => 'La categoría del gasto es obligatoria',
            'category.enum' => 'La categoría del gasto no es válida',
            'nature.required' => 'La naturaleza del gasto es obligatoria',
            'nature.enum' => 'La naturaleza del gasto no es válida',
            'amount.required' => 'El monto es obligatorio',
            'amount.numeric' => 'El monto debe ser un número',
            'amount.min' => 'El monto debe ser mayor que cero',
            'amount.max' => 'El monto no puede superar los 99999999.99',
            'expense_date.required' => 'La fecha del gasto es obligatoria',
            'expense_date.date' => 'La fecha del gasto no es válida',
            'expense_date.before_or_equal' => 'La fecha del gasto no puede ser futura',
            'description.required' => 'La descripción es obligatoria',
            'description.string' => 'La descripción debe ser texto',
            'description.max' => 'La descripción no puede superar los 1000 caracteres',
        ];
    }
}
