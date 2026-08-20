<?php

namespace App\Http\Requests\Accessory;

use App\Models\Accessory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessoryRequest extends FormRequest
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
     *
     * The two normalizers differ on purpose: the name collapses inner whitespace, the
     * code does not, because `A 100` and `A100` are two different codes.
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
         * status y registeredBy no se aceptan: el accesorio nace activo y la autoría sale
         * del usuario autenticado. currentValue tampoco: es un campo derivado de solo
         * salida, y mandarlo se ignora en silencio.
         */
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('accessories', 'name')],
            /** Único global: la unicidad no mira el status, así que un inactive no libera su código. */
            'code' => ['required', 'string', 'max:255', Rule::unique('accessories', 'code')],
            'description' => ['nullable', 'string', 'max:1000'],
            /** Cero es un error de captura: un accesorio siempre costó algo. */
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            /** Un accesorio se registra cuando ya se compró; una fecha futura sería una orden de compra. */
            'purchaseDate' => ['required', 'date', 'before_or_equal:today'],
            /** Se admite 0: hay activos que no se deprecian y siempre valen su precio. */
            'annualDepreciation' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del accesorio es obligatorio',
            'name.string' => 'El nombre del accesorio debe ser texto',
            'name.max' => 'El nombre del accesorio no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe un accesorio con ese nombre',
            'code.required' => 'El código del accesorio es obligatorio',
            'code.string' => 'El código del accesorio debe ser texto',
            'code.max' => 'El código del accesorio no puede superar los 255 caracteres',
            'code.unique' => 'Ya existe un accesorio con ese código',
            'description.string' => 'La descripción debe ser texto',
            'description.max' => 'La descripción no puede superar los 1000 caracteres',
            'price.required' => 'El precio es obligatorio',
            'price.numeric' => 'El precio debe ser numérico',
            'price.min' => 'El precio debe ser mayor a 0',
            'price.max' => 'El precio no puede superar 99999999.99',
            'purchaseDate.required' => 'La fecha de compra es obligatoria',
            'purchaseDate.date' => 'La fecha de compra debe ser una fecha válida',
            'purchaseDate.before_or_equal' => 'La fecha de compra no puede ser futura',
            'annualDepreciation.required' => 'El porcentaje de depreciación anual es obligatorio',
            'annualDepreciation.numeric' => 'El porcentaje de depreciación anual debe ser numérico',
            'annualDepreciation.min' => 'El porcentaje de depreciación anual no puede ser negativo',
            'annualDepreciation.max' => 'El porcentaje de depreciación anual no puede superar 100',
        ];
    }
}
