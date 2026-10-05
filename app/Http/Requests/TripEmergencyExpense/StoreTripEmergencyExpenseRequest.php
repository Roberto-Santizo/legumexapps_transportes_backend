<?php

namespace App\Http\Requests\TripEmergencyExpense;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreTripEmergencyExpenseRequest',
    title: 'Registro de un gasto emergente',
    description: <<<'TEXT'
    Cuerpo con el que la empresa transportista (o el administrador) registra UN gasto emergente —un imprevisto en carretera: una llanta pinchada, una grúa— sobre un viaje EN RUTA. TRES CAMPOS: amount y description OBLIGATORIOS, receipt OPCIONAL.

    ATENCIÓN — SI LLEVA COMPROBANTE EL CUERPO VA EN multipart/form-data, NO EN JSON: un archivo no viaja en un cuerpo JSON. Sin comprobante sirven los dos formatos.

    ATENCIÓN — NO SE ACEPTA tripId: el viaje va EN LA URL (/api/trips/{trip}/emergency-expenses). registeredBy tampoco: sale del token. Mandarlos se descarta en silencio, con 201.

    ATENCIÓN — NO HAY CONFIRMACIÓN: a diferencia del viático, el gasto emergente suma en totalAmount y en totalEmergencyExpensesAmount desde el momento en que se registra.

    ATENCIÓN — LA VALIDACIÓN DEL CUERPO CORRE ANTES QUE LAS CUATRO GUARDAS DEL SERVICE: solo el middleware role (403) va por delante. Un cuerpo inválido sobre un viaje inexistente devuelve 422, no 404.
    TEXT,
    required: ['amount', 'description'],
    properties: [
        new OA\Property(
            property: 'amount',
            description: 'Monto del gasto en GTQ. OBLIGATORIO, numérico, MAYOR QUE CERO (min:0.01) y como máximo 99999999.99 (el tope del decimal(10,2)). Mensajes: El monto es obligatorio / El monto debe ser un número / El monto debe ser mayor a 0 / El monto no puede superar 99999999.99. SALE COMO STRING de dos decimales en la respuesta.',
            type: 'number',
            format: 'float',
            minimum: 0.01,
            maximum: 99999999.99,
            example: 450,
        ),
        new OA\Property(
            property: 'description',
            description: 'Qué pasó. OBLIGATORIA —sin categorías, es lo único que explica el gasto—, texto de 255 caracteres como máximo. Se guarda TAL COMO SE TECLEA, con solo trim; solo espacios es 422 (La descripción es obligatoria).',
            type: 'string',
            maxLength: 255,
            example: 'Reparación de llanta pinchada en el km 85',
        ),
        new OA\Property(
            property: 'receipt',
            description: 'Comprobante: jpg, jpeg, png o pdf, de 3 MB (3072 KB) como máximo, límite inclusivo. OPCIONAL. Se guarda TAL CUAL, sin recorte ni recompresión. Mensajes: El comprobante debe ser un archivo / El comprobante debe ser un archivo jpg, jpeg, png o pdf / El comprobante no puede pesar más de 3 MB. Requiere upload_max_filesize y post_max_size de 4M o más en el servidor.',
            type: 'string',
            format: 'binary',
            nullable: true,
        ),
    ],
    type: 'object',
)]
class StoreTripEmergencyExpenseRequest extends FormRequest
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
         * Tres campos y ninguno más. `tripId` no se acepta —el viaje va en la URL— y
         * `registeredBy` tampoco: sale del token. El `max` del monto es el tope del
         * decimal(10,2), para que un desbordamiento sea 422 y no un 500 de Postgres.
         */
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'description' => ['required', 'string', 'max:255'],
            'receipt' => ['sometimes', 'nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:3072'],
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
            'description.required' => 'La descripción es obligatoria',
            'description.string' => 'La descripción debe ser texto',
            'description.max' => 'La descripción no puede superar los 255 caracteres',
            'receipt.file' => 'El comprobante debe ser un archivo',
            'receipt.mimes' => 'El comprobante debe ser un archivo jpg, jpeg, png o pdf',
            'receipt.max' => 'El comprobante no puede pesar más de 3 MB',
        ];
    }
}
