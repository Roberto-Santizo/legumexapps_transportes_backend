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
    parameter: 'quoteLatQuery',
    name: 'lat',
    description: <<<'TEXT'
    LATITUD del punto de destino, en [-90, 90]. Va en la QUERY STRING: GET /api/freight-rates/quote?lat=14.6349&lng=-90.5069&productId=7&fuelType=diesel.

    Es OBLIGATORIA. ATENCIÓN — a diferencia del filtro lat+lng del listado de zonas, aquí una coordenada ausente o fuera de rango NO SE IGNORA: lat=200 devuelve 422 (mensajes: La latitud del destino es obligatoria / La latitud debe ser un número / La latitud debe estar entre -90 y 90). La cotización es dinero, y un punto imposible tiene que decirse, no resolverse en silencio.

    Junto con lng determina la ZONA: el sistema busca la primera zona ACTIVA cuyo polígono contiene el punto. No se manda zoneId —eso es precisamente lo que el sistema resuelve— y no se calcula distancia, kilometraje ni ruta: del punto solo se deriva la zona que lo contiene. Si ninguna zona activa lo contiene, la respuesta es 404.
    TEXT,
    in: 'query',
    required: true,
    schema: new OA\Schema(type: 'number', format: 'float', maximum: 90, minimum: -90, example: 14.6349),
)]
#[OA\QueryParameter(
    parameter: 'quoteLngQuery',
    name: 'lng',
    description: <<<'TEXT'
    LONGITUD del punto de destino, en [-180, 180]. Obligatoria; ausente, no numérica o fuera de rango devuelve 422 (mensajes: La longitud del destino es obligatoria / La longitud debe ser un número / La longitud debe estar entre -180 y 180).

    Aquí las coordenadas van en parámetros separados, así que no hay ambigüedad de orden como en el area de Zones —donde el par es [latitud, longitud]—, pero lat sigue siendo la latitud y lng la longitud.

    Se asume que las zonas no se solapan. Si dos zonas activas contuvieran el punto, gana la de id más bajo: la elección es determinista, pero la respuesta no avisa de que había otra candidata.
    TEXT,
    in: 'query',
    required: true,
    schema: new OA\Schema(type: 'number', format: 'float', maximum: 180, minimum: -180, example: -90.5069),
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
     * de un 422 con mensaje en español. A diferencia del listado de zonas, aquí una
     * coordenada fuera de rango NO se ignora: la cotización es dinero, y un punto
     * imposible tiene que decirse, no resolverse en silencio.
     *
     * El precio del combustible no se acepta por ningún nombre: sale siempre del
     * FuelPrice vigente.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
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
            'lat.required' => 'La latitud del destino es obligatoria',
            'lat.numeric' => 'La latitud debe ser un número',
            'lat.between' => 'La latitud debe estar entre -90 y 90',
            'lng.required' => 'La longitud del destino es obligatoria',
            'lng.numeric' => 'La longitud debe ser un número',
            'lng.between' => 'La longitud debe estar entre -180 y 180',
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
