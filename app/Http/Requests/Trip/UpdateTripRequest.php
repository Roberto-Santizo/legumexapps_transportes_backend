<?php

namespace App\Http\Requests\Trip;

use App\Enums\TripStatus;
use App\Models\Trip;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'UpdateTripRequest',
    title: 'Actualización de viaje',
    description: <<<'TEXT'
    Cuerpo JSON para editar un viaje. Es EXCLUSIVO del rol administrator. TODOS LOS CAMPOS SON OPCIONALES y solo se toca lo que venga: un CUERPO VACÍO responde 200 como no-op, sin mover siquiera updatedAt. Opcional NO es vaciable: enviar una clave en blanco, de solo espacios o con null es 422.

    Son los mismos doce campos del alta MÁS status, y MENOS pilotId y vehicleId.

    ATENCIÓN — EL ADMINISTRADOR NO PUEDE ASIGNAR NI DESASIGNAR. pilotId y vehicleId NO SE ACEPTAN AQUÍ y mandarlos SE IGNORA EN SILENCIO CON 200: el viaje conserva la asignación que tuviera. Cambiar la tripulación es cosa de PATCH /api/trips/{trip}/assignment, exclusiva del carrier. Tampoco se reescriben assignedBy ni registeredBy: mandarlos se descarta igual. Consecuencia real: SI NINGUNA EMPRESA TOMA UN VIAJE, NADIE PUEDE DESATASCARLO POR API —la única salida es borrarlo y volver a crearlo—.

    ATENCIÓN — status SE ACEPTA Y NO HAY MÁQUINA DE ESTADOS. Los tres valores del enum son válidos en cualquier orden y NO se tocan las fechas: un finished puede volver a pending CONSERVANDO startDate y endDate, y quedan combinaciones que se contradicen con sus propias fechas. El frontend debe pintar el relato desde las FECHAS, no desde el status. Un valor fuera del enum es 422.

    ATENCIÓN — LOS CATÁLOGOS SE REVALIDAN SIEMPRE, aunque el cuerpo solo mueva una fecha: el service fusiona lo enviado sobre lo almacenado y vuelve a comprobar los cuatro. Un viaje que apunta a un puerto que se desactivó desde el alta responde 400 aunque el PATCH no toque locationId. Mismo reparto 422 / 400 que el alta: id inventado 422, cliente o naviera BORRADOS 400.

    ATENCIÓN — SI SE CAMBIA locationId O departurePointId HAY QUE REMANDAR LA POLILÍNEA. polyline es obligatoria si se envía, pero nada obliga a enviarla al cambiar el destino: la API NO la recalcula y NO AVISA, así que la guardada queda obsoleta y el mapa dibuja una ruta que ya no corresponde. Debe resolverse de nuevo con GET /api/places/directions y viajar en el MISMO PATCH.

    Las dos fechas planificadas DEJAN DE EXIGIR FUTURO —editar un viaje ya arrancado no puede obligar a reprogramarlo—; el orden entre ellas se mantiene y solo se comprueba cuando las dos viajan juntas. startDate y endDate NO se aceptan por ninguna vía: las pone el servidor en /start y /finish.

    Normalización idéntica a la del alta: order y container en MAYÚSCULAS con espacios colapsados; destination, transport y observations con solo trim.
    TEXT,
    properties: [
        new OA\Property(
            property: 'order',
            description: 'Referencia comercial. Opcional; si se envía es obligatoria, texto y de 255 caracteres como máximo (La orden es obligatoria / La orden debe ser texto / La orden no puede superar los 255 caracteres). Se normaliza a MAYÚSCULAS con espacios colapsados antes de validarse, así que una orden de solo espacios cae en order.required. Sigue sin ser única.',
            type: 'string',
            maxLength: 255,
            example: 'ORD-2026-0148',
        ),
        new OA\Property(
            property: 'clientId',
            description: 'Id del cliente exportador. Opcional; si se envía es obligatorio, entero y con exists:clients,id (El cliente es obligatorio / El cliente debe ser un identificador válido / El cliente seleccionado no existe). Un cliente BORRADO pasa el exists: y lo para el service con 400 «El cliente seleccionado fue eliminado». Se revalida también cuando NO se envía, con el valor almacenado.',
            type: 'integer',
            example: 3,
        ),
        new OA\Property(
            property: 'shippingLineId',
            description: 'Id de la naviera. Opcional; si se envía es obligatorio, entero y con exists:shipping_lines,id (La naviera es obligatoria / La naviera debe ser un identificador válido / La naviera seleccionada no existe). Una naviera BORRADA pasa el exists: y la para el service con 400 «La naviera seleccionada fue eliminada».',
            type: 'integer',
            example: 2,
        ),
        new OA\Property(
            property: 'departurePointId',
            description: 'Id del punto de partida. Opcional; si se envía es obligatorio, entero y con exists:departure_points,id (El punto de partida es obligatorio / El punto de partida debe ser un identificador válido / El punto de partida seleccionado no existe). Uno INACTIVO pasa el exists: y lo para el service con 400 «El punto de partida está inactivo». ATENCIÓN — cambiarlo deja la polilínea obsoleta si no se remanda.',
            type: 'integer',
            example: 5,
        ),
        new OA\Property(
            property: 'locationId',
            description: 'Id del PUERTO de destino. Opcional; si se envía es obligatorio, entero y con exists:locations,id (El puerto de destino es obligatorio / El puerto de destino debe ser un identificador válido / El puerto de destino seleccionado no existe). Sigue teniendo que ser de tipo port y estar activo: 400 «El destino seleccionado no es un puerto» o 400 «El puerto de destino está inactivo». ATENCIÓN — cambiarlo deja la polilínea obsoleta si no se remanda.',
            type: 'integer',
            example: 9,
        ),
        new OA\Property(
            property: 'destination',
            description: 'Destino final en el extranjero, texto libre. Opcional; si se envía es obligatorio, texto y de 255 caracteres como máximo (El destino final es obligatorio / El destino final debe ser texto / El destino final no puede superar los 255 caracteres). Se guarda con solo trim, conservando mayúsculas y minúsculas.',
            type: 'string',
            maxLength: 255,
            example: 'Amberes, Bélgica',
        ),
        new OA\Property(
            property: 'container',
            description: 'Identificación del contenedor. Opcional; si se envía es obligatoria, texto y de 255 caracteres como máximo (El contenedor es obligatorio / El contenedor debe ser texto / El contenedor no puede superar los 255 caracteres). Se normaliza a MAYÚSCULAS con espacios colapsados. Sigue sin ser único.',
            type: 'string',
            maxLength: 255,
            example: 'MSKU 483920 1',
        ),
        new OA\Property(
            property: 'transport',
            description: 'Medio o empresa de transporte. Opcional; si se envía es obligatorio, texto y de 255 caracteres como máximo (El transporte es obligatorio / El transporte debe ser texto / El transporte no puede superar los 255 caracteres). Se guarda con solo trim.',
            type: 'string',
            maxLength: 255,
            example: 'Rastra 40 pies',
        ),
        new OA\Property(
            property: 'recolectionDate',
            description: 'Fecha y hora planificadas de recolección. Opcional; si se envía es obligatoria y con formato válido (La fecha de recolección es obligatoria / La fecha de recolección no es válida). ATENCIÓN — AQUÍ YA NO SE EXIGE QUE SEA FUTURA, al contrario que en el alta: editar un viaje ya arrancado no puede obligar a reprogramarlo. Entra en formato parseable y sale en d-m-Y h:i:s A.',
            type: 'string',
            example: '2026-09-02 06:00:00',
        ),
        new OA\Property(
            property: 'shipDate',
            description: 'Fecha y hora planificadas de embarque. Opcional; si se envía es obligatoria, con formato válido y NO ANTERIOR a recolectionDate (La fecha de embarque es obligatoria / La fecha de embarque no es válida / La fecha de embarque no puede ser anterior a la de recolección). ATENCIÓN — tampoco se exige futuro, y la comparación de orden SOLO SE COMPRUEBA CUANDO LAS DOS FECHAS VIAJAN JUNTAS: mandar solo shipDate no se contrasta contra la recolección almacenada.',
            type: 'string',
            example: '2026-09-04 23:30:00',
        ),
        new OA\Property(
            property: 'polyline',
            description: 'Polilínea codificada de Google. Opcional; si se envía es obligatoria y texto (La ruta es obligatoria / La ruta debe ser texto). ATENCIÓN — ES EL CAMPO QUE HAY QUE RECORDAR: cambiar locationId o departurePointId sin remandarla deja guardada una ruta que ya no corresponde, y NI LA API AVISA NI LA RECALCULA. Se resuelve con GET /api/places/directions y se envía en este mismo PATCH.',
            type: 'string',
            example: 'ynzmDbpb_Ln@bAtEsC',
        ),
        new OA\Property(
            property: 'observations',
            description: 'Instrucciones del viaje. Opcional; si se envía es obligatoria y texto (Las observaciones son obligatorias / Las observaciones deben ser texto). No se puede vaciar: es el único canal de instrucciones hacia la empresa que ejecuta el viaje.',
            type: 'string',
            example: 'Carga refrigerada a -2 °C. Confirmar sello antes de salir.',
        ),
        new OA\Property(
            property: 'status',
            description: 'Estado del viaje, y EL ÚNICO CAMPO QUE NO EXISTE EN EL ALTA —allí el viaje nace pending y mandarlo se descarta—. Opcional; si se envía es obligatorio y debe ser uno de los tres valores del enum (El estado del viaje es obligatorio / El estado del viaje no es válido). ATENCIÓN — NO SE COMPRUEBA NINGUNA TRANSICIÓN: se puede ir hacia atrás, y mover el estado NO toca startDate ni endDate, así que un finished devuelto a pending conserva las dos fechas de ejecución y el resultado se contradice consigo mismo. Cuando estado y fechas discrepen, mandan las fechas. Un valor fuera del enum es 422.',
            type: 'string',
            enum: ['pending', 'in_route', 'finished'],
            example: 'in_route',
        ),
    ],
    type: 'object',
)]
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
