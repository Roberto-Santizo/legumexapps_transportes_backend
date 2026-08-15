<?php

namespace App\Http\Requests\Pilot;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePilotSalaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The single field this endpoint exists for, and it is required.
     *
     * Not `sometimes`: this PATCH is only ever called to change the salary, so an empty
     * body is a 422 and not a no-op. `min:0.01` closes the door on zero and on negative
     * values — putting somebody at zero is unlinking them, and that is another spec.
     *
     * `changedBy` is not accepted here nor anywhere else in the body: it comes from the
     * authenticated user.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'salary' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'salary.required' => 'El salario es obligatorio',
            'salary.numeric' => 'El salario debe ser un número en quetzales',
            'salary.min' => 'El salario debe ser mayor que cero',
            'salary.max' => 'El salario no puede superar los 99999999.99 quetzales',
        ];
    }
}
