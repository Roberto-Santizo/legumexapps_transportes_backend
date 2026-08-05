<?php

namespace App\Http\Requests\Vehicle;

use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'image' => ['sometimes', 'required', 'file', 'mimes:jpg,jpeg,png'],
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
            'image.required' => 'La imagen no puede estar vacía',
            'image.file' => 'La imagen debe ser un archivo',
            'image.mimes' => 'La imagen debe ser un archivo jpg, jpeg o png',
            'status.required' => 'El estado no puede estar vacío',
            'status.enum' => 'El estado del vehículo no es válido',
        ];
    }
}
