<?php

namespace App\Http\Requests\Place;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SearchPlacesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validates the query string, not a body.
     *
     * Every search is billed by the provider, so an invalid term is a 422 that
     * never leaves the application: a one-letter search returns noise and costs
     * exactly the same as a useful one.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['required', 'string', 'min:3', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'search.required' => 'El texto de búsqueda es obligatorio',
            'search.string' => 'El texto de búsqueda debe ser una cadena de texto',
            'search.min' => 'El texto de búsqueda debe tener al menos 3 caracteres',
            'search.max' => 'El texto de búsqueda no puede superar los 200 caracteres',
        ];
    }
}
