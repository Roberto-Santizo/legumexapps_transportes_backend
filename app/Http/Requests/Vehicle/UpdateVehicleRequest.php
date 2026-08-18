<?php

namespace App\Http\Requests\Vehicle;

use App\Enums\VehicleCondition;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateVehicleRequest',
    title: 'Actualización de vehículo',
    description: <<<'TEXT'
    Actualización parcial: los ocho campos son opcionales y solo se modifica lo que llega. Un campo enviado vacío sí es error (422). A diferencia del alta, aquí sí se acepta status.

    La empresa dueña del vehículo no se puede cambiar: no existe campo para ello.
    TEXT,
    properties: [
        new OA\Property(
            property: 'plate',
            description: 'Nueva placa. Se normaliza a mayúsculas. Solo se revalida la unicidad cuando cambia respecto a la que ya tiene el vehículo, así que reenviar la misma placa nunca es un conflicto consigo mismo. Si la placa nueva la usa un vehículo de cualquier empresa cuyo status no sea inactive, la respuesta es 400.',
            type: 'string',
            maxLength: 15,
            example: 'P456XYZ',
        ),
        new OA\Property(property: 'brand', description: 'Nueva marca. Si se envía, no puede estar vacía.', type: 'string', maxLength: 100, example: 'Freightliner'),
        new OA\Property(property: 'model', description: 'Nuevo modelo. Si se envía, no puede estar vacío.', type: 'string', maxLength: 100, example: 'Cascadia'),
        new OA\Property(
            property: 'year',
            description: 'Nuevo año. Entero entre 1900 y el año siguiente al actual.',
            type: 'integer',
            maximum: 2027,
            minimum: 1900,
            example: 2023,
        ),
        new OA\Property(
            property: 'capacity',
            description: 'Nueva capacidad de carga EN LIBRAS. Numérica y no negativa; la unidad no se valida.',
            type: 'number',
            format: 'float',
            minimum: 0,
            example: 18000.75,
        ),
        new OA\Property(
            property: 'type',
            description: 'Nuevo tipo de vehículo. Un valor fuera del enum devuelve 422.',
            type: 'string',
            enum: ['truck', 'van', 'trailer', 'pickup'],
            example: 'trailer',
        ),
        new OA\Property(
            property: 'image',
            description: 'Nuevo archivo de imagen. Solo jpg, jpeg y png, y no más de 3 MB (3072 KB, límite inclusivo). Igual que en el alta, se recorta a un cuadrado centrado de 800x800 px antes de subirla, conservando el formato. Al reemplazarla se borra la imagen anterior del almacenamiento, de forma irreversible. Requiere enviar el cuerpo como multipart/form-data.',
            type: 'string',
            format: 'binary',
        ),
        new OA\Property(
            property: 'status',
            description: 'Nuevo estado operativo. Un valor fuera del enum devuelve 422. ATENCIÓN: sacar un vehículo de inactive (a active o a under_repair) revalida su placa aunque no se envíe, porque un vehículo que vuelve al servicio no puede compartir placa con otro que ya la usa. Si en el intervalo otra empresa registró esa placa, la reactivación devuelve 400 con el mensaje: No puedes reactivar este vehículo: su placa ya está registrada en otro vehículo que no está desactivado. Resolver ese conflicto (liberar la placa o cambiarla en la misma operación) está fuera del alcance de la SPEC 04: hoy el vehículo se queda desactivado. Mientras el vehículo siga en inactive, su placa duplicada no molesta y el resto de campos se pueden actualizar con normalidad.',
            type: 'string',
            enum: ['active', 'inactive', 'under_repair'],
            example: 'under_repair',
        ),
    ],
    type: 'object',
)]
class UpdateVehicleRequest extends FormRequest
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
        return [
            'plate' => ['sometimes', 'required', 'string', 'max:15'],
            'brand' => ['sometimes', 'required', 'string', 'max:100'],
            'model' => ['sometimes', 'required', 'string', 'max:100'],
            'year' => ['sometimes', 'required', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'capacity' => ['sometimes', 'required', 'numeric', 'min:0'],
            'type' => ['sometimes', 'required', Rule::enum(VehicleType::class)],
            'condition' => ['sometimes', 'required', Rule::enum(VehicleCondition::class)],
            'kilometers_per_gallon' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'purchase_price' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'monthly_insurance_cost' => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'mileage' => ['sometimes', 'required', 'integer', 'min:0'],
            'engine_number' => ['sometimes', 'required', 'string', 'max:50'],
            'image' => ['sometimes', 'required', 'file', 'mimes:jpg,jpeg,png', 'max:3072'],
            'status' => ['sometimes', 'required', Rule::enum(VehicleStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plate.required' => 'La placa no puede estar vacía',
            'plate.string' => 'La placa debe ser texto',
            'plate.max' => 'La placa no puede superar los 15 caracteres',
            'brand.required' => 'La marca no puede estar vacía',
            'brand.string' => 'La marca debe ser texto',
            'brand.max' => 'La marca no puede superar los 100 caracteres',
            'model.required' => 'El modelo no puede estar vacío',
            'model.string' => 'El modelo debe ser texto',
            'model.max' => 'El modelo no puede superar los 100 caracteres',
            'year.required' => 'El año no puede estar vacío',
            'year.integer' => 'El año debe ser un número entero',
            'year.min' => 'El año no puede ser anterior a 1900',
            'year.max' => 'El año no puede ser posterior a '.(date('Y') + 1),
            'capacity.required' => 'La capacidad en libras no puede estar vacía',
            'capacity.numeric' => 'La capacidad debe ser un número en libras',
            'capacity.min' => 'La capacidad no puede ser negativa',
            'type.required' => 'El tipo de vehículo no puede estar vacío',
            'type.enum' => 'El tipo de vehículo no es válido',
            'condition.required' => 'La condición del vehículo no puede estar vacía',
            'condition.enum' => 'La condición del vehículo no es válida',
            'kilometers_per_gallon.required' => 'El rendimiento en kilómetros por galón no puede estar vacío',
            'kilometers_per_gallon.numeric' => 'El rendimiento debe ser un número en kilómetros por galón',
            'kilometers_per_gallon.min' => 'El rendimiento debe ser mayor que cero',
            'purchase_price.required' => 'El valor de compra no puede estar vacío',
            'purchase_price.numeric' => 'El valor de compra debe ser un número',
            'purchase_price.min' => 'El valor de compra debe ser mayor que cero',
            'monthly_insurance_cost.required' => 'El costo mensual del seguro no puede estar vacío',
            'monthly_insurance_cost.numeric' => 'El costo mensual del seguro debe ser un número',
            'monthly_insurance_cost.min' => 'El costo mensual del seguro debe ser mayor que cero',
            'mileage.required' => 'El kilometraje no puede estar vacío',
            'mileage.integer' => 'El kilometraje debe ser un número entero de kilómetros',
            'mileage.min' => 'El kilometraje no puede ser negativo',
            'engine_number.required' => 'El número de motor no puede estar vacío',
            'engine_number.string' => 'El número de motor debe ser texto',
            'engine_number.max' => 'El número de motor no puede superar los 50 caracteres',
            'image.required' => 'La imagen no puede estar vacía',
            'image.file' => 'La imagen debe ser un archivo',
            'image.mimes' => 'La imagen debe ser un archivo jpg, jpeg o png',
            'image.max' => 'La imagen no puede pesar más de 3 MB',
            'status.required' => 'El estado no puede estar vacío',
            'status.enum' => 'El estado del vehículo no es válido',
        ];
    }
}
