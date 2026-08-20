<?php

namespace App\Http\Requests\AccessoryCharacteristic;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexAccessoryCharacteristicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validates the query string, not a body.
     *
     * `accessoryId` is required, like the `vehicleId` of the expenses: the
     * characteristics of a single accessory are the only listing this domain
     * serves, so omitting it is a 422 rather than a listing of the whole
     * inventory nobody asked for. An accessory that does not exist is still a
     * 404 raised by the service, so no `exists` rule lives here: the 422 is
     * reserved for the parameter being absent.
     *
     * `limit` is deliberately unvalidated, like everywhere else in the project:
     * a non numeric one means "do not paginate" instead of failing the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'accessoryId' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accessoryId.required' => 'El accesorio es obligatorio',
            'accessoryId.integer' => 'El accesorio debe ser un número entero',
        ];
    }
}
