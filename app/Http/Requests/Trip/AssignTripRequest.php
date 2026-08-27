<?php

namespace App\Http\Requests\Trip;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AssignTripRequest',
    title: 'Asignación de tripulación a un viaje',
    description: <<<'TEXT'
    Cuerpo JSON con el que una empresa transportista TOMA un viaje. EXACTAMENTE DOS CAMPOS —pilotId y vehicleId— Y LOS DOS SON OBLIGATORIOS: no se puede asignar solo el piloto o solo el vehículo, y faltar cualquiera de ellos es 422.

    ATENCIÓN — ES EXCLUSIVO DEL ROL carrier Y ADEMÁS EXIGE EMPRESA. La ruta lleva role:carrier y carrier.required: un administrator, un manager o un pilot reciben 403 «No tienes permisos para acceder a este recurso», y un carrier que todavía no ha registrado su empresa recibe 403 «Debes estar vinculado a un transportista para acceder a este recurso» ANTES DE LLEGAR AL SERVICE. EL ADMINISTRADOR NO PUEDE ASIGNAR POR NINGUNA VÍA, y el PATCH general tampoco acepta estos dos campos.

    ATENCIÓN — NO EXISTE LA DESASIGNACIÓN. Mandar null en cualquiera de los dos es 422: una vez tomado, un viaje NO VUELVE NUNCA A LA BOLSA y pilot_id y vehicle_id no regresan a null por ninguna vía. Se pueden CAMBIAR —reasignar— pero solo mientras el viaje siga pending y solo desde la empresa que ya lo tomó; sobre un viaje in_route o finished la respuesta es 400 «Solo se puede asignar un viaje pendiente». Consecuencia real: si ninguna empresa toma un viaje, o lo toma la equivocada y no lo suelta, la única salida por API es borrarlo y crearlo de nuevo.

    assignedBy NO SE ENVÍA: sale del usuario autenticado, que por el middleware es siempre un transportista, y se escribe junto con los otros dos —no existe un viaje con piloto y sin assignedBy—. Guarda el USUARIO, pero el ámbito compara su EMPRESA: cualquier compañero de esa empresa ve el viaje y puede reasignarlo.

    ATENCIÓN — REPARTO 422 / 400: el exists: convierte un id inventado en 422; que el usuario tenga rol de piloto, que tenga empresa, que el vehículo esté active y que los dos sean de la MISMA empresa son reglas de negocio y salen como 400 desde el service, cada una con su mensaje literal.

    La escritura corre dentro de una transacción con bloqueo de fila, así que dos transportistas que intenten tomar el mismo viaje a la vez no se pisan: solo uno gana y el otro recibe 403 o 400.
    TEXT,
    required: ['pilotId', 'vehicleId'],
    properties: [
        new OA\Property(
            property: 'pilotId',
            description: 'Id del usuario que conducirá el viaje (users.id). OBLIGATORIO, entero y con regla exists:users,id (mensajes: El piloto es obligatorio / El piloto debe ser un identificador válido / El piloto seleccionado no existe). ATENCIÓN — el exists: solo comprueba que el usuario EXISTA: que tenga rol pilot y que pertenezca a una empresa son reglas del service y salen como 400 con «El usuario seleccionado no es un piloto» y «El piloto seleccionado no pertenece a ninguna empresa transportista». null es 422: la desasignación no existe.',
            type: 'integer',
            example: 12,
        ),
        new OA\Property(
            property: 'vehicleId',
            description: 'Id del vehículo que hará el viaje (vehicles.id). OBLIGATORIO, entero y con regla exists:vehicles,id (mensajes: El vehículo es obligatorio / El vehículo debe ser un identificador válido / El vehículo seleccionado no existe). ATENCIÓN — debe estar en estado active: un inactive o un under_repair responden 400 «El vehículo seleccionado no está activo». Y debe ser de LA MISMA EMPRESA que el piloto: si no, 400 «El piloto y el vehículo deben pertenecer a la misma empresa transportista». null es 422.',
            type: 'integer',
            example: 8,
        ),
    ],
    type: 'object',
)]
class AssignTripRequest extends FormRequest
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
        /**
         * Exactamente dos campos y los dos obligatorios: no se puede asignar solo piloto o
         * solo vehículo, y `null` en cualquiera de los dos es 422 porque la desasignación no
         * existe —una vez tomado, un viaje no vuelve nunca a la bolsa—.
         *
         * `assignedBy` no se acepta: sale del usuario autenticado, que por el middleware
         * role:carrier es siempre un transportista.
         *
         * El `exists:` convierte un id inventado en 422; que el usuario tenga rol de piloto,
         * que tenga empresa, que el vehículo esté activo y que los dos sean de la misma
         * empresa son reglas de negocio y las levanta el service con su 400.
         */
        return [
            'pilotId' => ['required', 'integer', 'exists:users,id'],
            'vehicleId' => ['required', 'integer', 'exists:vehicles,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pilotId.required' => 'El piloto es obligatorio',
            'pilotId.integer' => 'El piloto debe ser un identificador válido',
            'pilotId.exists' => 'El piloto seleccionado no existe',
            'vehicleId.required' => 'El vehículo es obligatorio',
            'vehicleId.integer' => 'El vehículo debe ser un identificador válido',
            'vehicleId.exists' => 'El vehículo seleccionado no existe',
        ];
    }
}
