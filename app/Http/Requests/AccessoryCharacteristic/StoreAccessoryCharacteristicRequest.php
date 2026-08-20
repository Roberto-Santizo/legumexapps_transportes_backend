<?php

namespace App\Http\Requests\AccessoryCharacteristic;

use App\Models\AccessoryCharacteristic;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreAccessoryCharacteristicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name and the value before the rules run.
     *
     * The two normalizers are asymmetric on purpose: the name is an identifier, so it
     * is trimmed, collapsed and upper cased; the value is user content, so it is only
     * trimmed — upper casing «Diésel» would ruin it.
     *
     * Normalizing before validating also makes `max:500` count the trimmed value, not
     * the padding around it.
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
         * Sin regla unique: repetir un nombre en el mismo accesorio lo corta el service
         * con un 400, no un 422. Es la asimetría deliberada de la spec — el índice único
         * protege la integridad y la guarda del service entrega el mensaje de negocio.
         *
         * registeredBy no se acepta: la autoría sale del usuario autenticado.
         */
        return [
            /** El 422 por `exists` cubre el id inexistente antes de que el service levante su 404. */
            'accessory_id' => ['required', 'integer', 'exists:accessories,id'],
            'name' => ['required', 'string', 'max:255'],
            /** 500 es el límite de la columna, no una regla de negocio. */
            'value' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accessory_id.required' => 'El accesorio es obligatorio',
            'accessory_id.integer' => 'El accesorio debe ser un número entero',
            'accessory_id.exists' => 'El accesorio no existe',
            'name.required' => 'El nombre de la característica es obligatorio',
            'name.string' => 'El nombre de la característica debe ser texto',
            'name.max' => 'El nombre de la característica no puede superar los 255 caracteres',
            'value.required' => 'El valor de la característica es obligatorio',
            'value.string' => 'El valor de la característica debe ser texto',
            'value.max' => 'El valor de la característica no puede superar los 500 caracteres',
        ];
    }
}
