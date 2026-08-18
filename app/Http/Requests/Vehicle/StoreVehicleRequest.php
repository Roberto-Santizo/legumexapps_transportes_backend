<?php

namespace App\Http\Requests\Vehicle;

use App\Enums\VehicleCondition;
use App\Enums\VehicleType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreVehicleRequest',
    title: 'Alta de vehículo',
    description: <<<'TEXT'
    Cuerpo multipart/form-data para registrar un vehículo. Los siete campos son obligatorios: omitir cualquiera devuelve 422.

    El status NO se acepta: el vehículo nace siempre en active y enviarlo en el cuerpo no cambia nada, se descarta sin error. El carrier_id tampoco se envía: se resuelve desde la empresa del usuario autenticado.

    La unicidad de la placa no es una regla de este FormRequest, vive en el servicio y depende del estado de las filas existentes, por lo que una placa ya tomada devuelve 400 y no 422.
    TEXT,
    required: ['plate', 'brand', 'model', 'year', 'capacity', 'type', 'image'],
    properties: [
        new OA\Property(
            property: 'plate',
            description: 'Placa del vehículo. Se normaliza a mayúsculas antes de validar la unicidad y antes de persistir, así que p123abc y P123ABC son la misma placa.',
            type: 'string',
            maxLength: 15,
            example: 'P123ABC',
        ),
        new OA\Property(property: 'brand', description: 'Marca del vehículo.', type: 'string', maxLength: 100, example: 'Kenworth'),
        new OA\Property(property: 'model', description: 'Modelo del vehículo.', type: 'string', maxLength: 100, example: 'T680'),
        new OA\Property(
            property: 'year',
            description: 'Año del vehículo. Debe ser un entero entre 1900 y el año siguiente al actual; fuera de ese rango devuelve 422.',
            type: 'integer',
            maximum: 2027,
            minimum: 1900,
            example: 2021,
        ),
        new OA\Property(
            property: 'capacity',
            description: 'Capacidad de carga EN LIBRAS. Debe ser numérica y no negativa. La unidad es solo una convención del dominio: nada impide enviar kilos y nada lo detectará.',
            type: 'number',
            format: 'float',
            minimum: 0,
            example: 15000.5,
        ),
        new OA\Property(
            property: 'type',
            description: 'Tipo de vehículo. Un valor fuera del enum devuelve 422.',
            type: 'string',
            enum: ['truck', 'van', 'trailer', 'pickup'],
            example: 'truck',
        ),
        new OA\Property(
            property: 'image',
            description: 'Archivo de imagen, obligatorio. Solo se aceptan jpg, jpeg y png; cualquier otro tipo devuelve 422. No puede pesar más de 3 MB (3072 KB, límite inclusivo). El archivo se almacena, pero NO tal cual: antes de subirlo se recorta a un cuadrado centrado y se reescala a 800x800 px, conservando el formato de entrada. Se pierden los bordes del lado largo y el original no se guarda en ningún sitio. Si la imagen no se puede procesar o el almacenamiento falla, la respuesta es 400 y el vehículo no se crea.',
            type: 'string',
            format: 'binary',
        ),
    ],
    type: 'object',
)]
class StoreVehicleRequest extends FormRequest
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
            'plate' => ['required', 'string', 'max:15'],
            'brand' => ['required', 'string', 'max:100'],
            'model' => ['required', 'string', 'max:100'],
            'year' => ['required', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'capacity' => ['required', 'numeric', 'min:0'],
            'type' => ['required', Rule::enum(VehicleType::class)],
            'condition' => ['required', Rule::enum(VehicleCondition::class)],
            'kilometers_per_gallon' => ['required', 'numeric', 'min:0.01'],
            'purchase_price' => ['required', 'numeric', 'min:0.01'],
            'monthly_insurance_cost' => ['required', 'numeric', 'min:0.01'],
            'mileage' => ['required', 'integer', 'min:0'],
            'engine_number' => ['required', 'string', 'max:50'],
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:3072'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plate.required' => 'La placa es obligatoria',
            'plate.string' => 'La placa debe ser texto',
            'plate.max' => 'La placa no puede superar los 15 caracteres',
            'brand.required' => 'La marca es obligatoria',
            'brand.string' => 'La marca debe ser texto',
            'brand.max' => 'La marca no puede superar los 100 caracteres',
            'model.required' => 'El modelo es obligatorio',
            'model.string' => 'El modelo debe ser texto',
            'model.max' => 'El modelo no puede superar los 100 caracteres',
            'year.required' => 'El año es obligatorio',
            'year.integer' => 'El año debe ser un número entero',
            'year.min' => 'El año no puede ser anterior a 1900',
            'year.max' => 'El año no puede ser posterior a '.(date('Y') + 1),
            'capacity.required' => 'La capacidad en libras es obligatoria',
            'capacity.numeric' => 'La capacidad debe ser un número en libras',
            'capacity.min' => 'La capacidad no puede ser negativa',
            'type.required' => 'El tipo de vehículo es obligatorio',
            'type.enum' => 'El tipo de vehículo no es válido',
            'condition.required' => 'La condición del vehículo es obligatoria',
            'condition.enum' => 'La condición del vehículo no es válida',
            'kilometers_per_gallon.required' => 'El rendimiento en kilómetros por galón es obligatorio',
            'kilometers_per_gallon.numeric' => 'El rendimiento debe ser un número en kilómetros por galón',
            'kilometers_per_gallon.min' => 'El rendimiento debe ser mayor que cero',
            'purchase_price.required' => 'El valor de compra es obligatorio',
            'purchase_price.numeric' => 'El valor de compra debe ser un número',
            'purchase_price.min' => 'El valor de compra debe ser mayor que cero',
            'monthly_insurance_cost.required' => 'El costo mensual del seguro es obligatorio',
            'monthly_insurance_cost.numeric' => 'El costo mensual del seguro debe ser un número',
            'monthly_insurance_cost.min' => 'El costo mensual del seguro debe ser mayor que cero',
            'mileage.required' => 'El kilometraje es obligatorio',
            'mileage.integer' => 'El kilometraje debe ser un número entero de kilómetros',
            'mileage.min' => 'El kilometraje no puede ser negativo',
            'engine_number.required' => 'El número de motor es obligatorio',
            'engine_number.string' => 'El número de motor debe ser texto',
            'engine_number.max' => 'El número de motor no puede superar los 50 caracteres',
            'image.required' => 'La imagen es obligatoria',
            'image.file' => 'La imagen debe ser un archivo',
            'image.mimes' => 'La imagen debe ser un archivo jpg, jpeg o png',
            'image.max' => 'La imagen no puede pesar más de 3 MB',
        ];
    }
}
