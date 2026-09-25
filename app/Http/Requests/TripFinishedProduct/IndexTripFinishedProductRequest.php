<?php

namespace App\Http\Requests\TripFinishedProduct;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IndexTripFinishedProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validates the query string, not a body.
     *
     * `tripId` is required: the lines of a single trip are the only listing this domain
     * serves. A trip that does not exist —or has been deleted— is a 404 raised by the
     * service, so no `exists` rule lives here. `limit` is not read: the listing never
     * paginates.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tripId' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tripId.required' => 'El viaje es obligatorio',
            'tripId.integer' => 'El viaje debe ser un número entero',
        ];
    }
}
