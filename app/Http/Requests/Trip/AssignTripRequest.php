<?php

namespace App\Http\Requests\Trip;

use App\Enums\FuelType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'AssignTripRequest',
    title: 'Asignación de tripulación a un viaje',
    description: <<<'TEXT'
    Cuerpo JSON con el que una empresa transportista TOMA un viaje. EXACTAMENTE CUATRO CAMPOS —pilotId, vehicleId, fuelGallons y fuelType— Y LOS CUATRO SON OBLIGATORIOS: no se puede asignar solo el piloto, solo el vehículo ni la tripulación sin combustible, y faltar cualquiera de ellos es 422.

    ATENCIÓN — CAMBIO INCOMPATIBLE SIN PERIODO DE GRACIA (SPEC 27). Este cuerpo pasó de DOS campos a CUATRO: un cliente que siga mandando solo pilotId y vehicleId recibe 422 EN TODAS SUS ASIGNACIONES, con «Los galones de combustible son obligatorios» y «El tipo de combustible es obligatorio». No hay periodo de convivencia ni valor por defecto, igual que el POST de vehículos en SPEC 13, el de destinos en SPEC 21 y el register de SPEC 25. Asignar tripulación y asignar combustible son el mismo acto: así ningún viaje queda asignado con cero cargas.

    ATENCIÓN — LA ASIGNACIÓN INSERTA LA PRIMERA CARGA DE COMBUSTIBLE, dentro de la MISMA TRANSACCIÓN y detrás del mismo bloqueo de fila: si el INSERT falla, la asignación entera se deshace y NO QUEDA NINGUNA FILA en trip_fuels. Igual que si falla cualquiera de las guardas (403 o 400): no se crea nada. La carga nace SIN CONFIRMAR —loadedAt y confirmedBy en null—, así que el viaje sale de aquí con totalFuelGallons en "0.00" hasta que el piloto pase por PATCH /api/trip-fuels/{tripFuel}/confirm, Y NO PUEDE ARRANCAR MIENTRAS NO LO HAGA: /start responde 400 «Debes confirmar al menos una carga de combustible antes de iniciar el viaje».

    ATENCIÓN — REASIGNAR AÑADE OTRA CARGA, NO PISA LA ANTERIOR. Cada llamada válida deja una fila nueva en trip_fuels, así que reasignar dos veces un viaje pending deja DOS cargas y las dos suman en cuanto se confirmen. Es el único rastro que deja una reasignación —piloto y vehículo se sobrescriben sin dejar historial— y sale gratis. Y como la tabla es APPEND-ONLY, esa carga de más no se puede borrar ni corregir por API.

    ATENCIÓN — ES EXCLUSIVO DEL ROL carrier Y ADEMÁS EXIGE EMPRESA. La ruta lleva role:carrier y carrier.required: un administrator, un manager o un pilot reciben 403 «No tienes permisos para acceder a este recurso», y un carrier que todavía no ha registrado su empresa recibe 403 «Debes estar vinculado a un transportista para acceder a este recurso» ANTES DE LLEGAR AL SERVICE. EL ADMINISTRADOR NO PUEDE ASIGNAR POR NINGUNA VÍA, y el PATCH general tampoco acepta estos dos campos.

    ATENCIÓN — NO EXISTE LA DESASIGNACIÓN. Mandar null en cualquiera de los cuatro es 422: una vez tomado, un viaje NO VUELVE NUNCA A LA BOLSA y pilot_id y vehicle_id no regresan a null por ninguna vía. Se pueden CAMBIAR —reasignar— pero solo mientras el viaje siga pending y solo desde la empresa que ya lo tomó; sobre un viaje in_route o finished la respuesta es 400 «Solo se puede asignar un viaje pendiente». Consecuencia real: si ninguna empresa toma un viaje, o lo toma la equivocada y no lo suelta, la única salida por API es borrarlo y crearlo de nuevo.

    assignedBy NO SE ENVÍA, y tampoco nada del ciclo de vida de la carga —loadedAt, confirmedBy ni registeredBy—: sale del usuario autenticado, que por el middleware es siempre un transportista, y se escribe junto con los otros dos —no existe un viaje con piloto y sin assignedBy—. Guarda el USUARIO, pero el ámbito compara su EMPRESA: cualquier compañero de esa empresa ve el viaje y puede reasignarlo.

    ATENCIÓN — REPARTO 422 / 400: el exists: convierte un id inventado en 422; que el usuario tenga rol de piloto, que tenga empresa, que el vehículo esté active y que los dos sean de la MISMA empresa son reglas de negocio y salen como 400 desde el service, cada una con su mensaje literal. Los dos campos de combustible NO añaden ningún 400: se validan solo contra el formato y el enum.

    La escritura corre dentro de una transacción con bloqueo de fila, así que dos transportistas que intenten tomar el mismo viaje a la vez no se pisan: solo uno gana y el otro recibe 403 o 400.
    TEXT,
    required: ['pilotId', 'vehicleId', 'fuelGallons', 'fuelType'],
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
        new OA\Property(
            property: 'fuelGallons',
            description: 'Galones de la PRIMERA carga de combustible del viaje, la que esta llamada inserta en trip_fuels. OBLIGATORIO desde SPEC 27 —CAMBIO INCOMPATIBLE—, numérico y MAYOR QUE CERO (min:0.01), con los mensajes literales: Los galones de combustible son obligatorios / Los galones de combustible deben ser un número / Los galones de combustible deben ser mayores a 0. ATENCIÓN — SIN NINGUNA VALIDACIÓN CRUZADA: no se compara contra vehicles.kilometersPerGallon, ni contra la distancia del viaje, ni contra un techo de negocio. Y la carga es INCORREGIBLE: la tabla es append-only, así que un 450 en vez de un 45 se queda para siempre. La carga nace SIN CONFIRMAR, así que estos galones NO suman en totalFuelGallons hasta que el piloto la confirme.',
            type: 'number',
            format: 'float',
            minimum: 0.01,
            example: 45.5,
        ),
        new OA\Property(
            property: 'fuelType',
            description: 'Tipo de combustible de esa primera carga, uno de los cuatro casos del enum FuelType de SPEC 06 y con el valor CRUDO EN INGLÉS (regular, premium, diesel, diesel_premium). OBLIGATORIO desde SPEC 27; fuera del enum es 422 (mensajes: El tipo de combustible es obligatorio / El tipo de combustible seleccionado no es válido). ATENCIÓN — no se comprueba contra la tabla fuel_prices: se puede asignar diesel aunque ningún precio de diesel esté active, porque aquí no se guarda ni el precio ni el costo. Es POR CARGA: las siguientes cargas del viaje, registradas con POST /api/trips/{trip}/fuels, pueden llevar otro tipo.',
            type: 'string',
            enum: ['regular', 'premium', 'diesel', 'diesel_premium'],
            example: 'diesel',
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
         * Cuatro campos y los cuatro obligatorios desde SPEC 27: asignar tripulación y
         * asignar combustible son el mismo acto, así que ningún viaje queda asignado con
         * cero cargas. Es cambio incompatible sin periodo de gracia —un cliente que siga
         * mandando dos campos recibe 422—, como el POST de vehículos en SPEC 13 y el de
         * destinos en SPEC 21.
         *
         * `null` en cualquiera de ellos es 422 porque la desasignación no existe —una vez
         * tomado, un viaje no vuelve nunca a la bolsa—.
         *
         * `assignedBy` no se acepta: sale del usuario autenticado, que por el middleware
         * role:carrier es siempre un transportista. Tampoco se acepta nada del ciclo de
         * vida de la carga: nace sin confirmar y la confirma su piloto aparte.
         *
         * El `exists:` convierte un id inventado en 422; que el usuario tenga rol de piloto,
         * que tenga empresa, que el vehículo esté activo y que los dos sean de la misma
         * empresa son reglas de negocio y las levanta el service con su 400.
         *
         * `fuelGallons` no se cruza con nada: ni con `vehicles.kilometers_per_gallon`, ni
         * con la distancia, ni con un techo de negocio. Y `fuelType` se valida solo contra
         * los cuatro casos del enum: no se exige que ese tipo tenga un precio vigente,
         * porque aquí no se guarda ningún precio.
         */
        return [
            'pilotId' => ['required', 'integer', 'exists:users,id'],
            'vehicleId' => ['required', 'integer', 'exists:vehicles,id'],
            'fuelGallons' => ['required', 'numeric', 'min:0.01'],
            'fuelType' => ['required', Rule::enum(FuelType::class)],
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
            'fuelGallons.required' => 'Los galones de combustible son obligatorios',
            'fuelGallons.numeric' => 'Los galones de combustible deben ser un número',
            'fuelGallons.min' => 'Los galones de combustible deben ser mayores a 0',
            'fuelType.required' => 'El tipo de combustible es obligatorio',
            'fuelType.enum' => 'El tipo de combustible seleccionado no es válido',
        ];
    }
}
