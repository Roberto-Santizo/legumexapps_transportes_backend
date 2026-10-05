<?php

namespace App\Http\Requests\TripEmergencyExpense;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateTripEmergencyExpenseRequest',
    title: 'Corrección de un gasto emergente',
    description: <<<'TEXT'
    Cuerpo para corregir un gasto emergente. CUATRO CAMPOS, TODOS OPCIONALES: amount, description, receipt y removeReceipt. Un cuerpo vacío responde 200 SIN ESCRIBIR NADA (updatedAt no cambia).

    ATENCIÓN — CON ARCHIVO, EL CUERPO VA EN multipart/form-data Y LA PETICIÓN DEBE SER POST CON _method=PATCH: PHP no llena $_FILES en un PATCH real. Es la misma mecánica que el PATCH de vehículos.

    ATENCIÓN — receipt REEMPLAZA el comprobante y removeReceipt=true LO QUITA; en los dos casos el archivo anterior se borra del almacenamiento de forma irreversible, después de guardar la fila. Mandar receipt Y removeReceipt=true a la vez es 422 y no toca nada.

    ATENCIÓN — tripId, trip_id y registeredBy son INMUTABLES: si se mandan se ignoran en silencio, con 200.
    TEXT,
    properties: [
        new OA\Property(
            property: 'amount',
            description: 'Nuevo monto en GTQ. Si viaja, numérico, mayor que cero y como máximo 99999999.99; vacío o null es 422 (El monto no puede estar vacío).',
            type: 'number',
            format: 'float',
            minimum: 0.01,
            maximum: 99999999.99,
            example: 475.5,
        ),
        new OA\Property(
            property: 'description',
            description: 'Nueva descripción, de 255 caracteres como máximo, con solo trim. Si viaja no puede quedar vacía (La descripción no puede estar vacía).',
            type: 'string',
            maxLength: 255,
            example: 'Reparación de llanta y cambio de válvula',
        ),
        new OA\Property(
            property: 'receipt',
            description: 'Nuevo comprobante (jpg, jpeg, png o pdf, máximo 3 MB). REEMPLAZA al anterior, que se borra del almacenamiento. Incompatible con removeReceipt=true (422: No puedes enviar un comprobante y pedir que se quite al mismo tiempo).',
            type: 'string',
            format: 'binary',
            nullable: true,
        ),
        new OA\Property(
            property: 'removeReceipt',
            description: 'true quita el comprobante: la fila queda con receiptUrl en null y el archivo se borra del almacenamiento. Acepta true/false, 1/0 y las cadenas "true"/"false" (para multipart). false o ausente no hace nada.',
            type: 'boolean',
            example: false,
        ),
    ],
    type: 'object',
)]
class UpdateTripEmergencyExpenseRequest extends FormRequest
{
    /**
     * Values accepted as a true `removeReceipt`, including the string a multipart body carries.
     *
     * @var list<bool|int|string>
     */
    private const TRUE_VALUES = [true, 1, '1', 'true'];

    /**
     * Values accepted as a false `removeReceipt`.
     *
     * @var list<bool|int|string>
     */
    private const FALSE_VALUES = [false, 0, '0', 'false'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Cast `removeReceipt` to a real boolean, but only when the key is present.
     *
     * A multipart body only carries strings, so `"true"` and `"false"` are accepted
     * besides Laravel's own boolean representations. Anything else is left untouched so
     * the `boolean` rule rejects it.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('removeReceipt')) {
            return;
        }

        $value = $this->input('removeReceipt');

        if (in_array($value, self::TRUE_VALUES, true)) {
            $this->merge(['removeReceipt' => true]);

            return;
        }

        if (in_array($value, self::FALSE_VALUES, true)) {
            $this->merge(['removeReceipt' => false]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * `trip_id` y `registered_by` no están: son inmutables y se ignoran en silencio.
         * Replace and remove at once is contradictory, so it is refused before anything
         * reaches the bucket.
         */
        return [
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'receipt' => [
                'sometimes',
                'nullable',
                Rule::prohibitedIf(fn (): bool => $this->input('removeReceipt') === true),
                'file',
                'mimes:jpg,jpeg,png,pdf',
                'max:3072',
            ],
            'removeReceipt' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'El monto no puede estar vacío',
            'amount.numeric' => 'El monto debe ser un número',
            'amount.min' => 'El monto debe ser mayor a 0',
            'amount.max' => 'El monto no puede superar 99999999.99',
            'description.required' => 'La descripción no puede estar vacía',
            'description.string' => 'La descripción debe ser texto',
            'description.max' => 'La descripción no puede superar los 255 caracteres',
            'receipt.prohibited' => 'No puedes enviar un comprobante y pedir que se quite al mismo tiempo',
            'receipt.file' => 'El comprobante debe ser un archivo',
            'receipt.mimes' => 'El comprobante debe ser un archivo jpg, jpeg, png o pdf',
            'receipt.max' => 'El comprobante no puede pesar más de 3 MB',
            'removeReceipt.boolean' => 'removeReceipt debe ser verdadero o falso',
        ];
    }
}
