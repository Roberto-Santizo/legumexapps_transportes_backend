<?php

namespace App\Http\Requests\AccessoryCharacteristic;

use App\Models\AccessoryCharacteristic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccessoryCharacteristicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name and the value before the rules run, exactly as the store does.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => AccessoryCharacteristic::normalizeName($this->input('name'))]);
        }

        if (is_string($this->input('value'))) {
            $this->merge(['value' => AccessoryCharacteristic::normalizeValue($this->input('value'))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * accessory_id no aparece a propósito: la característica no se mueve de accesorio,
         * y mandarlo se ignora en silencio en vez de dar 422. Los dos campos son
         * opcionales por separado y un cuerpo vacío se acepta como no-op.
         */
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'value' => ['sometimes', 'required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la característica es obligatorio',
            'name.string' => 'El nombre de la característica debe ser texto',
            'name.max' => 'El nombre de la característica no puede superar los 255 caracteres',
            'value.required' => 'El valor de la característica es obligatorio',
            'value.string' => 'El valor de la característica debe ser texto',
            'value.max' => 'El valor de la característica no puede superar los 500 caracteres',
        ];
    }
}
