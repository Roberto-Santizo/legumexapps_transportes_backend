<?php

namespace App\Http\Requests\Trip;

use App\Models\Trip;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreTripRequest',
    title: 'Alta de viaje',
    description: <<<'TEXT'
    Cuerpo JSON para publicar un viaje de exportación. Son DOCE CAMPOS Y LOS DOCE SON OBLIGATORIOS: order, clientId, shippingLineId, departurePointId, locationId, destination, container, transport, recolectionDate, shipDate, polyline y observations. Falta cualquiera de ellos y la respuesta es 422. Es EXCLUSIVO del rol administrator.

    ATENCIÓN — CINCO CAMPOS SE DESCARTAN SIN ERROR: status, pilotId, vehicleId, assignedBy y registeredBy. Mandarlos no cambia nada y no da 422. El viaje NACE pending, SIN TRIPULACIÓN —pilotId, vehicleId y assignedById salen en null— y el registeredBy se toma del usuario autenticado. La tripulación solo la escribe PATCH /api/trips/{trip}/assignment, que es del carrier: EL ADMINISTRADOR NO PUEDE ASIGNAR POR NINGUNA VÍA.

    ATENCIÓN — EL REPARTO 422 / 400 ES LA TRAMPA PRINCIPAL DEL ALTA. Las cuatro claves foráneas llevan regla exists:, así que un id INVENTADO es 422. Pero esa regla lee la tabla EN CRUDO y NO VE EL BORRADO LÓGICO: un clientId o un shippingLineId de una fila BORRADA pasa la validación y lo para el service con 400 —«El cliente seleccionado fue eliminado» / «La naviera seleccionada fue eliminada»—. Las demás reglas de negocio también son 400 desde el service, cada una con su mensaje literal: «El destino seleccionado no es un puerto», «El puerto de destino está inactivo» y «El punto de partida está inactivo».

    ATENCIÓN — LA POLILÍNEA LA MANDA EL FRONTEND Y LA API NUNCA LLAMA A GOOGLE. polyline es obligatoria aquí y también en el PATCH; se resuelve antes con GET /api/places/directions (SPEC 16) y se envía ya calculada. El backend no la recalcula ni la valida contra el par punto de partida / puerto.

    NORMALIZACIÓN ASIMÉTRICA: order y container se guardan EN MAYÚSCULAS y con los espacios interiores COLAPSADOS a uno; destination, transport y observations se guardan TAL COMO SE TECLEAN, con solo trim. NI order NI container SON ÚNICOS: dos viajes pueden compartir los dos, sin 400 ni 422.

    Cualquier otra clave que se envíe se descarta en silencio.
    TEXT,
    required: ['order', 'clientId', 'shippingLineId', 'departurePointId', 'locationId', 'destination', 'container', 'transport', 'recolectionDate', 'shipDate', 'polyline', 'observations'],
    properties: [
        new OA\Property(
            property: 'order',
            description: 'Referencia comercial del viaje. OBLIGATORIA, texto y de 255 caracteres como máximo (mensajes: La orden es obligatoria / La orden debe ser texto / La orden no puede superar los 255 caracteres). Se normaliza ANTES de validarse: recorte, COLAPSO de los espacios internos a uno y MAYÚSCULAS, así que "  ord-2026   0148  " guarda "ORD-2026 0148". Una orden de solo espacios sale por order.required, porque el recorte la deja vacía antes de validarse. ATENCIÓN — NO ES ÚNICA: repetirla es legal y no da ni 400 ni 422.',
            type: 'string',
            maxLength: 255,
            example: 'ORD-2026-0148',
        ),
        new OA\Property(
            property: 'clientId',
            description: 'Id del cliente exportador (clients.id). OBLIGATORIO y entero, con regla exists:clients,id (mensajes: El cliente es obligatorio / El cliente debe ser un identificador válido / El cliente seleccionado no existe). ATENCIÓN — un id inventado es 422, pero un cliente BORRADO pasa el exists: —que no ve el borrado lógico— y lo para el service con 400 «El cliente seleccionado fue eliminado».',
            type: 'integer',
            example: 3,
        ),
        new OA\Property(
            property: 'shippingLineId',
            description: 'Id de la naviera (shipping_lines.id). OBLIGATORIO y entero, con regla exists:shipping_lines,id (mensajes: La naviera es obligatoria / La naviera debe ser un identificador válido / La naviera seleccionada no existe). Mismo reparto que el cliente: id inventado 422, naviera BORRADA 400 «La naviera seleccionada fue eliminada».',
            type: 'integer',
            example: 2,
        ),
        new OA\Property(
            property: 'departurePointId',
            description: 'Id del punto de partida (departure_points.id, SPEC 20). OBLIGATORIO y entero, con regla exists:departure_points,id (mensajes: El punto de partida es obligatorio / El punto de partida debe ser un identificador válido / El punto de partida seleccionado no existe). ATENCIÓN — el exists: no mira el status: un punto de partida INACTIVO pasa la validación y lo para el service con 400 «El punto de partida está inactivo».',
            type: 'integer',
            example: 5,
        ),
        new OA\Property(
            property: 'locationId',
            description: 'Id del PUERTO de destino (locations.id, SPEC 15 y SPEC 21). OBLIGATORIO y entero, con regla exists:locations,id (mensajes: El puerto de destino es obligatorio / El puerto de destino debe ser un identificador válido / El puerto de destino seleccionado no existe). ATENCIÓN — NO SIRVE CUALQUIER DESTINO: la location debe ser de tipo port y estar activa, y ninguna de las dos cosas la comprueba el exists:. Un destino ordinario responde 400 «El destino seleccionado no es un puerto» y un puerto inactivo, 400 «El puerto de destino está inactivo».',
            type: 'integer',
            example: 9,
        ),
        new OA\Property(
            property: 'destination',
            description: 'Destino final en el extranjero. OBLIGATORIO, texto y de 255 caracteres como máximo (mensajes: El destino final es obligatorio / El destino final debe ser texto / El destino final no puede superar los 255 caracteres). ES TEXTO LIBRE: no lo respalda ningún catálogo, no es una location y no se puede filtrar por él. Se guarda TAL COMO SE TECLEA, con solo trim: conserva mayúsculas, minúsculas y espacios interiores.',
            type: 'string',
            maxLength: 255,
            example: 'Rotterdam, Países Bajos',
        ),
        new OA\Property(
            property: 'container',
            description: 'Identificación del contenedor. OBLIGATORIA, texto y de 255 caracteres como máximo (mensajes: El contenedor es obligatorio / El contenedor debe ser texto / El contenedor no puede superar los 255 caracteres). Se normaliza igual que order: recorte, colapso de espacios internos y MAYÚSCULAS. ATENCIÓN — TAMPOCO ES ÚNICO: dos viajes pueden llevar el mismo contenedor.',
            type: 'string',
            maxLength: 255,
            example: 'MSKU 483920 1',
        ),
        new OA\Property(
            property: 'transport',
            description: 'Medio o empresa de transporte. OBLIGATORIO, texto y de 255 caracteres como máximo (mensajes: El transporte es obligatorio / El transporte debe ser texto / El transporte no puede superar los 255 caracteres). Se guarda con solo trim, sin mayúsculas ni colapso de espacios. Es descriptivo y NO tiene relación con el vehículo que después asigne el transportista.',
            type: 'string',
            maxLength: 255,
            example: 'Rastra 40 pies',
        ),
        new OA\Property(
            property: 'recolectionDate',
            description: 'Fecha y hora planificadas de recolección. OBLIGATORIA y con formato de fecha válido, y ADEMÁS DEBE SER FUTURA respecto al momento del alta (mensajes: La fecha de recolección es obligatoria / La fecha de recolección no es válida / La fecha de recolección debe ser futura). ATENCIÓN — de ENTRADA se acepta cualquier formato que Laravel parsee, típicamente ISO 8601, pero de SALIDA vuelve con el formato propio d-m-Y h:i:s A: no coinciden. En el PATCH la exigencia de futuro desaparece.',
            type: 'string',
            example: '2026-09-02 06:00:00',
        ),
        new OA\Property(
            property: 'shipDate',
            description: 'Fecha y hora planificadas de embarque. OBLIGATORIA, con formato válido, FUTURA y NUNCA ANTERIOR a recolectionDate (mensajes: La fecha de embarque es obligatoria / La fecha de embarque no es válida / La fecha de embarque debe ser futura / La fecha de embarque no puede ser anterior a la de recolección). Igual que la anterior, entra en formato parseable y sale en d-m-Y h:i:s A.',
            type: 'string',
            example: '2026-09-04 23:30:00',
        ),
        new OA\Property(
            property: 'polyline',
            description: 'Polilínea codificada de Google con la ruta prevista. OBLIGATORIA y texto (mensajes: La ruta es obligatoria / La ruta debe ser texto). ATENCIÓN — LA RESUELVE EL FRONTEND con GET /api/places/directions y la API NUNCA LLAMA A GOOGLE ni la recalcula: se guarda tal cual llega, sin comprobar que corresponda al punto de partida y al puerto enviados. No tiene límite de longitud —la columna es TEXT—.',
            type: 'string',
            example: 'ynzmDbpb_Ln@bAtEsC',
        ),
        new OA\Property(
            property: 'observations',
            description: 'Instrucciones del viaje. OBLIGATORIAS y texto (mensajes: Las observaciones son obligatorias / Las observaciones deben ser texto). Son obligatorias a propósito, aunque parezcan un campo de notas: si el alta la hace el administrador y el viaje lo ejecuta otra empresa, es el ÚNICO CANAL DE INSTRUCCIONES del dominio. Se guardan tal como se teclean, con solo trim, y no tienen límite de longitud.',
            type: 'string',
            example: 'Carga refrigerada a -2 °C.',
        ),
    ],
    type: 'object',
)]
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
