<?php

namespace App\Http\Requests\Trip;

use App\Enums\TripStatus;
use App\Models\Trip;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize the two commercial references before the rules run.
     *
     * Same rule as the store request: without the trim happening here, a reference of
     * only spaces would pass as a blank string instead of failing required.
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
         * Los mismos campos del alta como `sometimes|required` —un cuerpo vacío es un no-op
         * con 200, pero mandar una clave en blanco es 422—, más `status` y menos `pilotId` y
         * `vehicleId`: reasignar es cosa de PATCH /{trip}/assignment y del transportista, no
         * del administrador. Mandarlos, como mandar assignedBy o registeredBy, se descarta
         * en silencio con 200.
         *
         * `status` no se comprueba contra ninguna transición: un viaje finalizado puede
         * volver a pendiente conservando sus dos fechas de ejecución.
         *
         * Las dos fechas dejan de exigir futuro: editar un viaje ya arrancado no puede
         * obligar a reprogramarlo. El orden entre ellas sí se mantiene, y solo se comprueba
         * cuando las dos viajan juntas.
         */
        return [
            'order' => ['sometimes', 'required', 'string', 'max:255'],
            'clientId' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
            'shippingLineId' => ['sometimes', 'required', 'integer', 'exists:shipping_lines,id'],
            'departurePointId' => ['sometimes', 'required', 'integer', 'exists:departure_points,id'],
            'locationId' => ['sometimes', 'required', 'integer', 'exists:locations,id'],
            'destination' => ['sometimes', 'required', 'string', 'max:255'],
            'container' => ['sometimes', 'required', 'string', 'max:255'],
            'transport' => ['sometimes', 'required', 'string', 'max:255'],
            'recolectionDate' => ['sometimes', 'required', 'date'],
            'shipDate' => ['sometimes', 'required', 'date', 'after_or_equal:recolectionDate'],
            'polyline' => ['sometimes', 'required', 'string'],
            'observations' => ['sometimes', 'required', 'string'],
            'status' => ['sometimes', 'required', Rule::enum(TripStatus::class)],
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
            'shipDate.required' => 'La fecha de embarque es obligatoria',
            'shipDate.date' => 'La fecha de embarque no es válida',
            'shipDate.after_or_equal' => 'La fecha de embarque no puede ser anterior a la de recolección',
            'polyline.required' => 'La ruta es obligatoria',
            'polyline.string' => 'La ruta debe ser texto',
            'observations.required' => 'Las observaciones son obligatorias',
            'observations.string' => 'Las observaciones deben ser texto',
            'status.required' => 'El estado del viaje es obligatorio',
            'status.enum' => 'El estado del viaje no es válido',
        ];
    }
}
