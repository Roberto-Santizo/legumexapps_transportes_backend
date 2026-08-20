<?php

namespace App\Http\Requests\Accessory;

use App\Enums\AccessoryStatus;
use App\Models\Accessory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
