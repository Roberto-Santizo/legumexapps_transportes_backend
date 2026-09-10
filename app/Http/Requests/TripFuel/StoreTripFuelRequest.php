<?php

namespace App\Http\Requests\TripFuel;

use App\Enums\FuelType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreTripFuelRequest',
    title: 'Registro de una carga de combustible',
    description: <<<'TEXT'
    Cuerpo JSON con el que la empresa transportista registra UNA carga de combustible sobre un viaje que ya tomó. EXACTAMENTE DOS CAMPOS —gallons y fuelType— Y LOS DOS SON OBLIGATORIOS. Un cuerpo vacío es 422 señalando los dos.

    ATENCIÓN — NO SE ACEPTA tripId: el viaje va EN LA URL (/api/trips/{trip}/fuels), igual que en las posiciones de SPEC 26. Y tampoco se acepta nada del ciclo de vida de la carga: loadedAt, confirmedBy ni registeredBy. La fecha la pone el servidor con now() CUANDO EL PILOTO CONFIRMA, el piloto sale de su propio token en esa otra llamada y el autor del alta sale del token de quien registra. Mandar cualquiera de ellos SE DESCARTA EN SILENCIO, con 201 y sin 422, como el vehicleId de un gasto de vehículo.

    ATENCIÓN — LA CARGA NACE SIN CONFIRMAR Y NO HAY FORMA DE CREARLA YA CONFIRMADA. Registrar no es confirmar: quien registra es el transportista y quien confirma es el piloto asignado, con PATCH /api/trip-fuels/{tripFuel}/confirm. Hasta entonces isConfirmed es false, loadedAt es null y ESTOS GALONES NO SUMAN NI EN totalGallons NI EN totalFuelGallons.

    ATENCIÓN — NO HAY NINGUNA VALIDACIÓN CRUZADA SOBRE gallons. No se compara contra vehicles.kilometersPerGallon, ni contra la distancia del viaje, ni contra un techo de negocio, ni contra lo ya cargado: 5 000 galones en una rastra se aceptan con 201. Y como la tabla es APPEND-ONLY —no hay PATCH ni DELETE de una carga—, un 450 tecleado en vez de un 45 se queda para siempre y no se puede compensar, porque los galones no admiten negativos. EL FRONTEND DEBE CONFIRMAR LA CANTIDAD ANTES DE MANDAR EL POST.

    ATENCIÓN — fuelType SE VALIDA SOLO CONTRA EL ENUM. No se exige que ese tipo tenga un FuelPrice vigente (SPEC 06): se puede cargar diesel aunque ningún precio de diesel esté active, porque aquí NO SE GUARDA NINGÚN PRECIO NI NINGÚN COSTO. Es una etiqueta, no una llave foránea. Cada carga lleva su propio tipo: dos cargas del mismo viaje pueden no coincidir y ambas se guardan.

    ATENCIÓN — LA VALIDACIÓN DEL CUERPO CORRE ANTES QUE LAS CUATRO GUARDAS DEL SERVICE, no después: solo el middleware role:carrier (403) va por delante. Un cuerpo inválido sobre un viaje INEXISTENTE devuelve 422, no 404, y sobre un viaje borrado, ajeno o ya finalizado también 422, no 400 ni 403. El orden de las cuatro guardas entre sí sí es contrato, pero solo se llega a ellas con un cuerpo válido.
    TEXT,
    required: ['gallons', 'fuelType'],
    properties: [
        new OA\Property(
            property: 'gallons',
            description: 'Galones de esta carga. OBLIGATORIO, numérico y MAYOR QUE CERO (min:0.01), con los mensajes literales: Los galones son obligatorios / Los galones deben ser un número / Los galones deben ser mayores a 0. Se guarda como decimal(8,2) y SALE COMO STRING de dos decimales en la respuesta, así que el valor devuelto no es idénticamente el enviado (20 entra y "20.00" sale). Acepta número o cadena numérica. 0 y los negativos son 422: no hay cargas de cero ni correcciones en negativo.',
            type: 'number',
            format: 'float',
            minimum: 0.01,
            example: 20,
        ),
        new OA\Property(
            property: 'fuelType',
            description: 'Tipo de combustible de ESTA carga, uno de los cuatro casos del enum FuelType de SPEC 06 y con el valor CRUDO EN INGLÉS (regular, premium, diesel, diesel_premium). OBLIGATORIO; fuera del enum es 422 (mensajes: El tipo de combustible es obligatorio / El tipo de combustible seleccionado no es válido). ATENCIÓN — no se comprueba contra la tabla fuel_prices: no se exige que el tipo tenga un precio vigente y no se guarda ni el precio ni el costo de la carga.',
            type: 'string',
            enum: ['regular', 'premium', 'diesel', 'diesel_premium'],
            example: 'diesel',
        ),
    ],
    type: 'object',
)]
class StoreTripFuelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * Dos campos y ninguno más. `tripId` no se acepta —el viaje va en la URL— y
         * `loadedAt`, `confirmedBy` y `registeredBy` tampoco: la fecha la pone el servidor
         * al confirmar, el piloto sale de su propio token y el autor del alta, del token de
         * quien registra. Mandarlos se descarta en silencio.
         *
         * No hay ninguna validación cruzada sobre `gallons`: no se compara contra
         * `vehicles.kilometers_per_gallon`, ni contra la distancia del viaje, ni contra un
         * techo de negocio. Y `fuelType` se valida solo contra el enum: no se exige que ese
         * tipo tenga un `FuelPrice` vigente, porque aquí no se guarda ningún precio.
         */
        return [
            'gallons' => ['required', 'numeric', 'min:0.01'],
            'fuelType' => ['required', Rule::enum(FuelType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gallons.required' => 'Los galones son obligatorios',
            'gallons.numeric' => 'Los galones deben ser un número',
            'gallons.min' => 'Los galones deben ser mayores a 0',
            'fuelType.required' => 'El tipo de combustible es obligatorio',
            'fuelType.enum' => 'El tipo de combustible seleccionado no es válido',
        ];
    }
}
