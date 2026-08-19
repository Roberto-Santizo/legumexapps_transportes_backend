<?php

namespace App\Http\Requests\FreightRate;

use App\Enums\FuelType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * This FormRequest validates the query string, so it has no body schema: its five
 * parameters are published as reusable component parameters instead.
 */
#[OA\QueryParameter(
    parameter: 'quoteLocationIdQuery',
    name: 'locationId',
    description: <<<'TEXT'
    Identificador del DESTINO al que se cotiza (locations.id). Va en la QUERY STRING: GET /api/freight-rates/quote?locationId=3&productId=7&fuelType=diesel.

    Es OBLIGATORIO. ATENCIÓN — CAMBIO INCOMPATIBLE: este parámetro SUSTITUYE a lat y lng, que YA NO SE ACEPTAN por ningún nombre. Mandarlos no filtra, no valida y no cambia la respuesta: se ignoran por completo, y la llamada falla con 422 por locationId faltante. La cotización por punto geográfico dejó de existir; el destino se da de alta antes en POST /api/locations y aquí se manda su id.

    ATENCIÓN — 422 y 400 no son lo mismo: un id que NO EXISTE devuelve 422 con "El destino seleccionado no existe" (regla exists), mientras que un destino que existe pero tiene status false devuelve 400 con "El destino seleccionado no está activo".

    Del destino NO se deriva distancia, kilometraje ni ruta, y sus coordenadas NO influyen en el precio: la tarifa depende del destino elegido, no de dónde esté. Corregir el pin de un destino no cambia ninguna cotización.
    TEXT,
    in: 'query',
    required: true,
    schema: new OA\Schema(type: 'integer', example: 3),
)]
#[OA\QueryParameter(
    parameter: 'quoteProductIdQuery',
    name: 'productId',
    description: <<<'TEXT'
    Identificador del producto a transportar (products.id). OBLIGATORIO; ausente o no entero devuelve 422 (mensajes: El producto es obligatorio / El producto debe ser un identificador numérico).

    ATENCIÓN — 422 y 400 no son lo mismo: un id que NO EXISTE devuelve 422 con "El producto seleccionado no existe" (regla exists), mientras que un producto que existe pero tiene status false devuelve 400 con "El producto seleccionado no está activo". La cotización trata el producto inactivo como inexistente.

    Solo se cotiza UN producto por llamada: no hay cotización múltiple.
    TEXT,
    in: 'query',
    required: true,
    schema: new OA\Schema(type: 'integer', example: 7),
)]
#[OA\QueryParameter(
    parameter: 'quoteFuelTypeQuery',
    name: 'fuelType',
    description: <<<'TEXT'
    Tipo de combustible con el que se cotiza. OBLIGATORIO y, a diferencia del filtro homónimo de GET /api/fuel-prices, NO se ignora si es inválido: fuelType=gasolina devuelve 422, no el listado completo (mensajes: El tipo de combustible es obligatorio / El tipo de combustible no es válido).

    Determina dos cosas: de qué FuelPrice con status active se lee el precio vigente y qué bandas del par se consideran. Si ese tipo no tiene ningún precio active, la respuesta es 400 con "No existe un precio vigente para el combustible indicado", aunque existan tarifas cotizadas.

    ATENCIÓN — el PRECIO del combustible no se envía por ningún nombre: aquí solo viaja el TIPO. El importe sale siempre del FuelPrice vigente, y cualquier parámetro extra que intente fijarlo se ignora por completo.
    TEXT,
    in: 'query',
    required: true,
    schema: new OA\Schema(type: 'string', enum: ['regular', 'premium', 'diesel', 'diesel_premium'], example: 'diesel'),
)]
#[OA\QueryParameter(
    parameter: 'quotePoundsQuery',
    name: 'pounds',
    description: <<<'TEXT'
    Peso de la carga EN LIBRAS. Es el ÚNICO parámetro OPCIONAL de la cotización: omitirlo devuelve la tarifa por libra con pounds y total en null, y el resto de la respuesta idéntica. Si se envía, debe ser numérico y estar entre 0.01 y 99999999.99; fuera de rango es 422 (mensajes: Las libras deben ser un número / Las libras deben ser mayores que cero / Las libras no pueden superar las 99999999.99).

    Enviarlo es la forma de obtener el TOTAL AUTORITATIVO: la API multiplica por el pricePerPound de SEIS decimales y redondea solo al final. Recalcularlo en pantalla con la tarifa redondeada a dos decimales da 20 250.00 en vez de 20 435.40 sobre 45 000 libras — 185 quetzales de diferencia en un solo flete.

    Las libras no cambian la tarifa: no hay escalas por volumen ni descuentos por cantidad. Tampoco se registra nada: mandar pounds no crea ningún viaje ni ninguna carga.
    TEXT,
    in: 'query',
    required: false,
    schema: new OA\Schema(type: 'number', format: 'float', maximum: 99999999.99, minimum: 0.01, example: 45000),
)]
class QuoteFreightRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validates the query string, not a body.
     *
     * Sin este FormRequest, un fuelType ausente acabaría en un error del service en vez
     * de un 422 con mensaje en español. Aquí nada es tolerante: la cotización es dinero,
     * y un destino ausente o inexistente tiene que decirse, no resolverse en silencio.
     *
     * El destino llega por id: lat y lng ya no se aceptan por ningún nombre, y mandarlos
     * no filtra, no valida y no cambia la respuesta.
     *
     * El precio del combustible no se acepta por ningún nombre: sale siempre del
     * FuelPrice vigente.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'locationId' => ['required', 'integer', 'exists:locations,id'],
            'productId' => ['required', 'integer', 'exists:products,id'],
            'fuelType' => ['required', Rule::enum(FuelType::class)],
            'pounds' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'locationId.required' => 'El destino es obligatorio',
            'locationId.integer' => 'El destino debe ser un identificador numérico',
            'locationId.exists' => 'El destino seleccionado no existe',
            'productId.required' => 'El producto es obligatorio',
            'productId.integer' => 'El producto debe ser un identificador numérico',
            'productId.exists' => 'El producto seleccionado no existe',
            'fuelType.required' => 'El tipo de combustible es obligatorio',
            'fuelType.enum' => 'El tipo de combustible no es válido',
            'pounds.numeric' => 'Las libras deben ser un número',
            'pounds.min' => 'Las libras deben ser mayores que cero',
            'pounds.max' => 'Las libras no pueden superar las 99999999.99',
        ];
    }
}
