<?php

namespace App\Http\Requests\VehicleExpense;

use App\Enums\VehicleExpenseCategory;
use App\Enums\VehicleExpenseNature;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
