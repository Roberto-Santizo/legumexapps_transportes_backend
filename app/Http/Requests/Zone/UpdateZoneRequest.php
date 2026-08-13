<?php

namespace App\Http\Requests\Zone;

use App\Models\Zone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the name and the colour before the rules run.
     *
     * Without the first step "zona norte" would pass the unique rule while ZONA NORTE
     * exists and blow up against the unique index with a 500 instead of a 422.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => Zone::normalizeName($this->input('name'))]);
        }

        if (is_string($this->input('color'))) {
            $this->merge(['color' => mb_strtoupper(trim($this->input('color')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * Todos los campos son opcionales por separado y no hay required_without cruzado: con
         * cinco campos editables encadenarlos produciría cinco mensajes idénticos en un 422.
         * Un PATCH con el cuerpo vacío se acepta como no-op. El ignore() del propio id permite
         * reenviar el mismo nombre sin chocar consigo mismo, y el área que llegue sustituye al
         * polígono anterior entero.
         */
        return [
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('zones', 'name')->ignore($this->route('zone'))],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'status' => ['sometimes', 'boolean'],
            'area' => ['sometimes', 'array', 'min:3'],
            'area.*' => ['required', 'array', 'size:2'],
            'area.*.0' => ['required', 'numeric', 'between:-90,90'],
            'area.*.1' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.string' => 'El nombre de la zona debe ser texto',
            'name.max' => 'El nombre de la zona no puede superar los 255 caracteres',
            'name.unique' => 'Ya existe una zona con ese nombre',
            'description.string' => 'La descripción debe ser texto',
            'description.max' => 'La descripción no puede superar los 1000 caracteres',
            'color.string' => 'El color debe ser texto',
            'color.regex' => 'El color debe ser hexadecimal con el formato #RRGGBB',
            'status.boolean' => 'El estado debe ser verdadero o falso',
            'area.array' => 'El área debe ser una lista de puntos',
            'area.min' => 'El área debe tener al menos 3 puntos',
            'area.*.required' => 'El punto :position es obligatorio',
            'area.*.array' => 'El punto :position debe ser un par de coordenadas',
            'area.*.size' => 'El punto :position debe tener exactamente 2 coordenadas: latitud y longitud',
            'area.*.0.required' => 'La latitud del punto :position es obligatoria',
            'area.*.0.numeric' => 'La latitud del punto :position debe ser numérica',
            'area.*.0.between' => 'La latitud del punto :position debe estar entre -90 y 90',
            'area.*.1.required' => 'La longitud del punto :position es obligatoria',
            'area.*.1.numeric' => 'La longitud del punto :position debe ser numérica',
            'area.*.1.between' => 'La longitud del punto :position debe estar entre -180 y 180',
        ];
    }
}
