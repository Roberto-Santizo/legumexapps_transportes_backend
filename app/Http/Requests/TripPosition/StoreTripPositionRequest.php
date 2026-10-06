<?php

namespace App\Http\Requests\TripPosition;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreTripPositionRequest',
    title: 'Reporte de una posición del viaje',
    description: <<<'TEXT'
    Cuerpo JSON con el que el piloto asignado reporta dónde está. EXACTAMENTE DOS CAMPOS —latitude y longitude— Y LOS DOS SON OBLIGATORIOS. Un cuerpo vacío es 422 señalando las dos coordenadas.

    ATENCIÓN — UN PUNTO POR PETICIÓN. No se acepta un array de puntos: no hay envío en lote y no lo habrá en esta spec. Un piloto que estuvo sin señal PIERDE EL TRAMO: cuando recupere red mandará su posición actual y el rastro tendrá un hueco en línea recta entre dos puntos lejanos, sin que nada indique que faltó cobertura.

    ATENCIÓN — recordedAt Y pilotId NO SE ACEPTAN Y MANDARLOS SE DESCARTA EN SILENCIO, con 200/201 y sin 422. La hora la pone el servidor con now() —aceptarla del dispositivo la haría falsificable y desordenaría el rastro— y el autor sale del token del piloto autenticado. Tampoco se acepta tripId: el viaje va en la URL. Cualquier otra clave del cuerpo se ignora igual.

    ATENCIÓN — NO HAY NINGUNA VALIDACIÓN GEOGRÁFICA. Los rangos son los del sistema de coordenadas y NADA MÁS: no se comprueba que el punto caiga cerca de la polyline del viaje, ni dentro de Guatemala, ni que el salto contra el punto anterior sea físicamente posible. Un piloto puede reportar coordenadas en Noruega y la API las guarda con 201. Es riesgo de dato falso, no de fuga: no toca ningún otro viaje ni ninguna otra empresa.

    NO HAY TELEMETRÍA: no se acepta speed, heading, accuracy, altitude ni battery. Solo dónde y cuándo.

    ATENCIÓN — LA VALIDACIÓN DEL CUERPO CORRE ANTES QUE LAS CUATRO GUARDAS DEL SERVICE, no después: solo el middleware role:pilot (403) va por delante. Un cuerpo inválido sobre un viaje INEXISTENTE devuelve 422, no 404, y sobre un viaje ajeno o que no está en ruta también 422, no 403 ni 400. El orden de las cuatro guardas entre sí sí es contrato, pero solo se llega a ellas con un cuerpo válido: un cuerpo perfecto sobre un viaje pending sigue siendo 400.
    TEXT,
    required: ['latitude', 'longitude'],
    properties: [
        new OA\Property(
            property: 'latitude',
            description: 'Latitud del punto. OBLIGATORIA, numérica y entre -90 y 90 (mensajes literales: La latitud es obligatoria / La latitud debe ser un número / La latitud debe estar entre -90 y 90). Se guarda como decimal(10,8) y SALE COMO STRING de ocho decimales en la respuesta, así que el valor devuelto no es idénticamente el enviado (14.628074 entra y «14.62807400» sale). Acepta número o cadena numérica.',
            type: 'number',
            format: 'double',
            maximum: 90,
            minimum: -90,
            example: 14.628074,
        ),
        new OA\Property(
            property: 'longitude',
            description: 'Longitud del punto. OBLIGATORIA, numérica y entre -180 y 180 (mensajes literales: La longitud es obligatoria / La longitud debe ser un número / La longitud debe estar entre -180 y 180). Se guarda como decimal(11,8) y también sale como string de ocho decimales. ATENCIÓN — el rango es el mundo entero: no está acotado a Guatemala ni a la ruta prevista.',
            type: 'number',
            format: 'double',
            maximum: 180,
            minimum: -180,
            example: -90.522554,
        ),
    ],
    type: 'object',
)]
class StoreTripPositionRequest extends FormRequest
{
    /**
     * The ISO 8601 shapes accepted for a point's time: with offset or `Z`, with or
     * without milliseconds. A time without zone is ambiguous and is rejected.
     */
    private const RECORDED_AT_FORMATS = [
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s.vP',
        'Y-m-d\TH:i:sp',
        'Y-m-d\TH:i:s.vp',
    ];

    /**
     * Seconds a point may sit in the future, to absorb the drift of the phone's clock.
     */
    private const FUTURE_TOLERANCE_SECONDS = 60;

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
         * Un arreglo de 1 a 1000 puntos, y un solo punto inválido tumba el lote entero. El
         * `pilotId` no se acepta: el autor sale del usuario autenticado y mandarlo se
         * descarta en silencio.
         *
         * Los rangos son los del sistema de coordenadas y nada más: no se comprueba que el
         * punto caiga cerca de la polilínea del viaje, ni dentro de Guatemala, ni que el
         * salto contra el punto anterior sea físicamente posible.
         *
         * `recordedAt` es ISO 8601 con zona obligatoria, con o sin milisegundos. Las
         * variantes con `p` existen solo para la `Z` de `toISOString()`: `date_format` vuelve
         * a formatear la fecha y la compara con el valor, y `P` escribiría `+00:00`. El techo
         * de un minuto en el futuro cubre la deriva del reloj del teléfono; el piso —nada
         * anterior al arranque del viaje— vive en el service, porque necesita el viaje.
         */
        return [
            'positions' => ['required', 'array', 'min:1', 'max:1000'],
            'positions.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'positions.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'positions.*.recordedAt' => [
                'bail',
                'required',
                'date_format:'.implode(',', self::RECORDED_AT_FORMATS),
                'before_or_equal:'.now()->addSeconds(self::FUTURE_TOLERANCE_SECONDS)->format('Y-m-d\TH:i:sP'),
            ],
        ];
    }

    /**
     * `:position` is the point's place in the array counting from one, so the message
     * names the point the way a person would.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'positions.required' => 'Las posiciones son obligatorias',
            'positions.array' => 'Las posiciones deben enviarse como un arreglo',
            'positions.min' => 'Debes enviar al menos una posición',
            'positions.max' => 'No puedes enviar más de 1000 posiciones por petición',
            'positions.*.latitude.required' => 'La latitud del punto :position es obligatoria',
            'positions.*.latitude.numeric' => 'La latitud del punto :position debe ser un número',
            'positions.*.latitude.between' => 'La latitud del punto :position debe estar entre -90 y 90',
            'positions.*.longitude.required' => 'La longitud del punto :position es obligatoria',
            'positions.*.longitude.numeric' => 'La longitud del punto :position debe ser un número',
            'positions.*.longitude.between' => 'La longitud del punto :position debe estar entre -180 y 180',
            'positions.*.recordedAt.required' => 'La hora del punto :position es obligatoria',
            'positions.*.recordedAt.date_format' => 'La hora del punto :position debe estar en formato ISO 8601 con zona horaria',
            'positions.*.recordedAt.before_or_equal' => 'La hora del punto :position no puede estar en el futuro',
        ];
    }
}
