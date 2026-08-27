<?php

namespace App\Http\Requests\Trip;

use App\Models\Trip;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the two commercial references before the rules run.
     *
     * Trimming happens here and not in the rules so that a reference of only spaces is
     * left empty and caught by required, instead of passing as a blank string.
     *
     * `destination`, `transport` and `observations` are deliberately left alone beyond
     * their own trim: they keep the casing they were typed with.
     */
    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['order', 'container'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = Trip::normalizeReference($this->input($field));
            }
        }

        foreach (['destination', 'transport', 'observations'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = trim($this->input($field));
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /**
         * Cinco campos no se aceptan y mandarlos se descarta sin error: status, porque el
         * viaje nace pendiente; pilotId, vehicleId y assignedBy, porque la tripulación solo
         * la escribe PATCH /{trip}/assignment y es del transportista, no del administrador;
         * y registeredBy, porque la autoría sale del usuario autenticado.
         *
         * Las cuatro FK llevan `exists:` contra su tabla, que es lo que convierte un id
         * inventado en 422. Ojo: la regla lee la tabla en crudo, sin el scope de borrado
         * lógico, así que un cliente o una naviera BORRADOS la pasan y los para el service
         * con un 400 propio. Las otras dos reglas de negocio —que el destino sea un puerto
         * activo y que el punto de partida esté activo— tampoco caben aquí.
         */
        return [
            'order' => ['required', 'string', 'max:255'],
            'clientId' => ['required', 'integer', 'exists:clients,id'],
            'shippingLineId' => ['required', 'integer', 'exists:shipping_lines,id'],
            'departurePointId' => ['required', 'integer', 'exists:departure_points,id'],
            'locationId' => ['required', 'integer', 'exists:locations,id'],
            'destination' => ['required', 'string', 'max:255'],
            'container' => ['required', 'string', 'max:255'],
            'transport' => ['required', 'string', 'max:255'],
            /** Las dos fechas planificadas van siempre al futuro, y el embarque nunca antes de la recolección. */
            'recolectionDate' => ['required', 'date', 'after:now'],
            'shipDate' => ['required', 'date', 'after:now', 'after_or_equal:recolectionDate'],
            /** La ruta ya resuelta por el front con GET /api/places/directions: la API no llama a Google. */
            'polyline' => ['required', 'string'],
            'observations' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'order.required' => 'La orden es obligatoria',
            'order.string' => 'La orden debe ser texto',
            'order.max' => 'La orden no puede superar los 255 caracteres',
            'clientId.required' => 'El cliente es obligatorio',
            'clientId.integer' => 'El cliente debe ser un identificador válido',
            'clientId.exists' => 'El cliente seleccionado no existe',
            'shippingLineId.required' => 'La naviera es obligatoria',
            'shippingLineId.integer' => 'La naviera debe ser un identificador válido',
            'shippingLineId.exists' => 'La naviera seleccionada no existe',
            'departurePointId.required' => 'El punto de partida es obligatorio',
            'departurePointId.integer' => 'El punto de partida debe ser un identificador válido',
            'departurePointId.exists' => 'El punto de partida seleccionado no existe',
            'locationId.required' => 'El puerto de destino es obligatorio',
            'locationId.integer' => 'El puerto de destino debe ser un identificador válido',
            'locationId.exists' => 'El puerto de destino seleccionado no existe',
            'destination.required' => 'El destino final es obligatorio',
            'destination.string' => 'El destino final debe ser texto',
            'destination.max' => 'El destino final no puede superar los 255 caracteres',
            'container.required' => 'El contenedor es obligatorio',
            'container.string' => 'El contenedor debe ser texto',
            'container.max' => 'El contenedor no puede superar los 255 caracteres',
            'transport.required' => 'El transporte es obligatorio',
            'transport.string' => 'El transporte debe ser texto',
            'transport.max' => 'El transporte no puede superar los 255 caracteres',
            'recolectionDate.required' => 'La fecha de recolección es obligatoria',
            'recolectionDate.date' => 'La fecha de recolección no es válida',
            'recolectionDate.after' => 'La fecha de recolección debe ser futura',
            'shipDate.required' => 'La fecha de embarque es obligatoria',
            'shipDate.date' => 'La fecha de embarque no es válida',
            'shipDate.after' => 'La fecha de embarque debe ser futura',
            'shipDate.after_or_equal' => 'La fecha de embarque no puede ser anterior a la de recolección',
            'polyline.required' => 'La ruta es obligatoria',
            'polyline.string' => 'La ruta debe ser texto',
            'observations.required' => 'Las observaciones son obligatorias',
            'observations.string' => 'Las observaciones deben ser texto',
        ];
    }
}
