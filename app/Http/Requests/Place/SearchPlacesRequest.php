<?php

namespace App\Http\Requests\Place;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * This FormRequest validates the query string, so it has no body schema: its only
 * parameter is published as a reusable component parameter instead.
 */
#[OA\QueryParameter(
    parameter: 'placesSearchQuery',
    name: 'search',
    description: <<<'TEXT'
    TEXTO LIBRE que se busca como dirección. Va en la QUERY STRING: GET /api/places?search=zona 4 guatemala. Es el ÚNICO parámetro del endpoint y es OBLIGATORIO.

    Se escribe tal cual lo teclea el usuario —calle, colonia, zona, municipio, nombre de un negocio o una mezcla de todo—: no hay sintaxis, ni campos separados, ni códigos postales, ni filtro por departamento. Los acentos y las mayúsculas dan igual.

    ATENCIÓN — un search inválido devuelve 422 y NUNCA LLEGA AL PROVEEDOR DE DIRECCIONES, que factura cada llamada. Son cuatro casos: ausente (El texto de búsqueda es obligatorio), cadena vacía (El texto de búsqueda es obligatorio), menos de 3 caracteres (El texto de búsqueda debe tener al menos 3 caracteres) y más de 200 caracteres (El texto de búsqueda no puede superar los 200 caracteres). Exactamente 3 y exactamente 200 caracteres SÍ son válidos. El mínimo de 3 es una defensa de costo, no de usabilidad: una sola letra devuelve ruido y se paga igual, así que el cliente debería además aplicar un debounce antes de llamar.

    ATENCIÓN — ese 422 sale con el formato de Laravel { message, errors: { search: [...] } }, NO con el sobre { statusCode, message, data } del resto de la API: lo emite la validación antes de entrar al controller.

    Una búsqueda válida SIN COINCIDENCIAS no es un error: devuelve 200 con data vacío, nunca 404.

    NO existe ningún otro parámetro. limit, pageSize, page y regionCode se ignoran por completo: el listado devuelve como mucho 10 direcciones, no pagina y no hay token de página siguiente. Tampoco hay tope de búsquedas por usuario ni throttle.
    TEXT,
    in: 'query',
    required: true,
    schema: new OA\Schema(type: 'string', maxLength: 200, minLength: 3, example: 'zona 4 guatemala'),
)]
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
