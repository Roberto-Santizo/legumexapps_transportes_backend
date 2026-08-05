<?php

namespace App\Http\Requests\Vehicle;

use App\Enums\VehicleType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png'],
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
            'image.required' => 'La imagen es obligatoria',
            'image.file' => 'La imagen debe ser un archivo',
            'image.mimes' => 'La imagen debe ser un archivo jpg, jpeg o png',
        ];
    }
}
