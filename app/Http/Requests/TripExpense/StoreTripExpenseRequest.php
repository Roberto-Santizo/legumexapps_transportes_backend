<?php

namespace App\Http\Requests\TripExpense;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreTripExpenseRequest',
    title: 'Registro de un viático',
    description: <<<'TEXT'
    Cuerpo JSON con el que la empresa transportista registra UN viático (dinero entregado al piloto) sobre un viaje que ya tomó. DOS CAMPOS —amount y description— Y SOLO amount ES OBLIGATORIO. Un cuerpo vacío es 422 señalando amount.

    ATENCIÓN — NO SE ACEPTA tripId: el viaje va EN LA URL (/api/trips/{trip}/expenses), igual que en las cargas de combustible de SPEC 27. Y tampoco se acepta nada del ciclo de vida del viático: receivedAt, confirmedBy ni registeredBy. La fecha la pone el servidor con now() CUANDO EL PILOTO CONFIRMA, el piloto sale de su propio token en esa otra llamada y el autor del alta sale del token de quien registra. Mandar cualquiera de ellos SE DESCARTA EN SILENCIO, con 201 y sin 422.

    ATENCIÓN — EL VIÁTICO NACE SIN CONFIRMAR Y NO HAY FORMA DE CREARLO YA CONFIRMADO. Registrar no es confirmar: quien registra es el transportista y quien confirma haber recibido el dinero es el piloto asignado, con PATCH /api/trip-expenses/{tripExpense}/confirm. Hasta entonces isConfirmed es false, receivedAt es null y ESTE MONTO NO SUMA NI EN totalAmount NI EN totalExpensesAmount.

    ATENCIÓN — NO HAY NINGUNA VALIDACIÓN CRUZADA SOBRE amount: ni contra un techo de negocio, ni contra la distancia del viaje, ni contra lo ya entregado. Y como la tabla es APPEND-ONLY —no hay PATCH ni DELETE de un viático—, un 3500 tecleado en vez de un 350 se queda para siempre y no se puede compensar, porque el monto no admite negativos. EL FRONTEND DEBE CONFIRMAR LA CANTIDAD ANTES DE MANDAR EL POST.

    ATENCIÓN — LA VALIDACIÓN DEL CUERPO CORRE ANTES QUE LAS CUATRO GUARDAS DEL SERVICE: solo el middleware role:carrier (403) va por delante. Un cuerpo inválido sobre un viaje INEXISTENTE devuelve 422, no 404, y sobre un viaje borrado, ajeno o ya finalizado también 422, no 400 ni 403.
    TEXT,
    required: ['amount'],
    properties: [
        new OA\Property(
            property: 'amount',
            description: 'Monto del viático en GTQ. OBLIGATORIO, numérico, MAYOR QUE CERO (min:0.01) y como máximo 99999999.99 (el tope del decimal(10,2)), con los mensajes literales: El monto es obligatorio / El monto debe ser un número / El monto debe ser mayor a 0 / El monto no puede superar 99999999.99. SALE COMO STRING de dos decimales en la respuesta (350 entra y "350.00" sale). Acepta número o cadena numérica. 0 y los negativos son 422.',
            type: 'number',
            format: 'float',
            minimum: 0.01,
            maximum: 99999999.99,
            example: 350,
        ),
        new OA\Property(
            property: 'description',
            description: 'Concepto del viático. OPCIONAL, texto de 255 caracteres como máximo (mensajes: La descripción debe ser texto / La descripción no puede superar los 255 caracteres). Se guarda TAL COMO SE TECLEA, con solo trim; ausente, null o solo espacios se guarda como null.',
            type: 'string',
            maxLength: 255,
            nullable: true,
            example: 'Alimentación y peajes',
        ),
    ],
    type: 'object',
)]
class StoreTripExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Trim the description before validating, and turn a blank one into `null`.
     *
     * Same treatment `observations` gets in `StoreTripRequest`: only trim, keeping the
     * casing it was typed with. A description of only spaces is stored as `null`, not
     * as an empty string, so «no concept» has exactly one representation.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('description'))) {
            $description = trim($this->input('description'));

            $this->merge(['description' => $description === '' ? null : $description]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * Dos campos y ninguno más. `tripId` no se acepta —el viaje va en la URL— y
         * `receivedAt`, `confirmedBy` y `registeredBy` tampoco: la fecha la pone el
         * servidor al confirmar, el piloto sale de su propio token y el autor del alta,
         * del token de quien registra. Mandarlos se descarta en silencio.
         *
         * El `max` es el tope del decimal(10,2): así un desbordamiento es 422 y no un
         * 500 de Postgres, como las estimaciones de SPEC 30.
         */
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'El monto es obligatorio',
            'amount.numeric' => 'El monto debe ser un número',
            'amount.min' => 'El monto debe ser mayor a 0',
            'amount.max' => 'El monto no puede superar 99999999.99',
            'description.string' => 'La descripción debe ser texto',
            'description.max' => 'La descripción no puede superar los 255 caracteres',
        ];
    }
}
