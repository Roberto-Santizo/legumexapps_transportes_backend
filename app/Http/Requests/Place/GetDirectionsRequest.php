<?php

namespace App\Http\Requests\Place;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

/**
 * This FormRequest validates the query string, so it has no body schema: its three
 * parameters are published as reusable component parameters instead.
 */
#[OA\QueryParameter(
    parameter: 'directionsLocationIdQuery',
    name: 'locationId',
    description: <<<'TEXT'
    Identificador del DESTINO al que se calcula la ruta (locations.id). Va en la QUERY STRING: GET /api/places/directions?locationId=7&lat=14.6248&lng=-90.5152.

    Es OBLIGATORIO, como los otros dos. Ausente o no entero devuelve 422 (mensajes: El destino es obligatorio / El destino debe ser un identificador numérico).

    ATENCIÓN — 422, 400 y 404 son tres cosas distintas: un id que NO EXISTE devuelve 422 con "El destino seleccionado no existe" (regla exists); un destino que existe pero tiene status false devuelve 400 con "El destino seleccionado no está activo"; y un destino activo al que NO SE LLEGA POR CARRETERA devuelve 404 con "No se encontró una ruta hacia el destino". Los dos primeros se resuelven ANTES de llamar al proveedor de direcciones externo, así que ni el id inexistente ni el destino inactivo facturan nada.

    El destino se da de alta antes en POST /api/locations y aquí se manda su id: sus coordenadas NO se envían por ningún nombre, las lee la API de la fila registrada. El destino es siempre el EXTREMO FINAL de la ruta; no existe la ruta inversa ni el viaje redondo, y el origen nunca se indica por locationId.

    ESTE ENDPOINT NO COTIZA: mandar el destino aquí no consulta tarifas, no lee el precio del combustible y no devuelve ningún importe. Para eso está GET /api/freight-rates/quote, que es otra llamada y la hace el cliente.
    TEXT,
    in: 'query',
    required: true,
    schema: new OA\Schema(type: 'integer', example: 7),
)]
#[OA\QueryParameter(
    parameter: 'directionsLatQuery',
    name: 'lat',
    description: <<<'TEXT'
    LATITUD DEL ORIGEN en grados decimales, de donde arranca la ruta. OBLIGATORIA; ausente, no numérica o fuera de [-90, 90] devuelve 422 (mensajes: La latitud de origen es obligatoria / La latitud de origen debe ser numérica / La latitud de origen debe estar entre -90 y 90).

    ATENCIÓN — el origen es un PAR DE COORDENADAS SUELTAS y NUNCA un locationId: no hay forma de pedir la ruta entre dos destinos registrados, ni de invertir el sentido mandando el destino como origen. Suele venir de GET /api/places/{place}, que devuelve latitude y longitude para reenviarlas tal cual aquí, o de la posición del dispositivo.

    El RANGO es lo único que se comprueba: nada garantiza que el punto sea tierra firme, que esté en Guatemala ni que tenga una carretera cerca. Un origen inalcanzable por carretera NO es 422 —el formato era correcto—: es 404 con "No se encontró una ruta hacia el destino".

    Cuidado con el orden al reenviar coordenadas: aquí cada una viaja con su nombre (lat y lng), pero los points de la respuesta y el area de Zones usan pares [latitud, longitud].
    TEXT,
    in: 'query',
    required: true,
    schema: new OA\Schema(type: 'number', format: 'float', maximum: 90, minimum: -90, example: 14.6248),
)]
#[OA\QueryParameter(
    parameter: 'directionsLngQuery',
    name: 'lng',
    description: <<<'TEXT'
    LONGITUD DEL ORIGEN en grados decimales. OBLIGATORIA; ausente, no numérica o fuera de [-180, 180] devuelve 422 (mensajes: La longitud de origen es obligatoria / La longitud de origen debe ser numérica / La longitud de origen debe estar entre -180 y 180). El rango es más ancho que el de lat, así que invertir el par no siempre falla: un origen ambiguo con los dos valores dentro de [-90, 90] se acepta sin error y calcula la ruta desde el sitio equivocado.

    Va siempre acompañada de lat: las dos son obligatorias y no hay valor por defecto ni origen implícito del usuario autenticado.

    NO existe ningún otro parámetro. travelMode, polylineQuality y limit se IGNORAN POR COMPLETO —enviarlos devuelve exactamente la misma respuesta—: son constantes del servidor, no opciones del cliente. Tampoco hay waypoints, ni rutas alternativas, ni instrucciones paso a paso, ni matriz de distancias, ni hora de salida que altere la duración.

    ATENCIÓN — los 422 de este endpoint salen con el formato de Laravel { message, errors: { lng: [...] } }, NO con el sobre { statusCode, message, data } del resto de la API, y NUNCA LLEGAN AL PROVEEDOR DE DIRECCIONES EXTERNO, que factura cada llamada de ruta más caro que una búsqueda de texto.
    TEXT,
    in: 'query',
    required: true,
    schema: new OA\Schema(type: 'number', format: 'float', maximum: 180, minimum: -180, example: -90.5152),
)]
class GetDirectionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validates the query string, not a body.
     *
     * The three parameters are required and nothing here is tolerant: unlike the
     * listing filters of the catalogues, an invalid value is not ignored. Every route
     * is billed by the provider —at a higher rate than a text search—, so a malformed
     * call is a 422 that never leaves the application.
     *
     * The destination arrives by id and the origin as two loose numbers: the origin is
     * never a registered location, and there is no reverse trip.
     *
     * Nothing else is accepted. travelMode, polylineQuality and limit are ignored
     * outright: they are constants of the service, not choices of the client.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'locationId' => ['required', 'integer', 'exists:locations,id'],
            /** El rango es lo único que se comprueba del origen: nada garantiza que sea tierra firme. */
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'locationId.required' => 'El destino es obligatorio',
            'locationId.integer' => 'El destino debe ser un identificador numérico',
            'locationId.exists' => 'El destino seleccionado no existe',
            'lat.required' => 'La latitud de origen es obligatoria',
            'lat.numeric' => 'La latitud de origen debe ser numérica',
            'lat.between' => 'La latitud de origen debe estar entre -90 y 90',
            'lng.required' => 'La longitud de origen es obligatoria',
            'lng.numeric' => 'La longitud de origen debe ser numérica',
            'lng.between' => 'La longitud de origen debe estar entre -180 y 180',
        ];
    }
}
