<?php

namespace App\Http\Requests\TripPosition;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'StoreTripPositionRequest',
    title: 'Lote de posiciones del viaje',
    description: <<<'TEXT'
    Cuerpo JSON con el que el piloto asignado reporta su rastro: UN ARREGLO positions DE 1 A 1000 PUNTOS, cada uno con latitude, longitude y recordedAt, los tres obligatorios. Desde SPEC 40 es la ÚNICA forma aceptada: el cuerpo viejo de un solo punto ({ latitude, longitude }) es 422 sobre positions, sin periodo de gracia.

    ATENCIÓN — UN SOLO PUNTO INVÁLIDO TUMBA EL LOTE ENTERO con 422 y no se guarda nada. Los mensajes nombran el punto contando desde 1 (La latitud del punto 10 es obligatoria), y la clave del error lleva el índice desde 0 (positions.9.latitude).

    ATENCIÓN — recordedAt ES LA HORA DEL DISPOSITIVO Y ES OBLIGATORIA, en ISO 8601 CON ZONA: con desfase (2026-10-06T14:32:05-06:00) o con Z (2026-10-06T20:32:05.123Z, lo que da toISOString() en JS), con o sin milisegundos. Sin zona (2026-10-06T14:32:05) es 422: una hora sin zona es ambigua. Los milisegundos se truncan al guardar. NO PUEDE ESTAR A MÁS DE 60 SEGUNDOS EN EL FUTURO (422); la tolerancia cubre la deriva del reloj del teléfono. Tampoco puede ser anterior al start_date del viaje, pero esa guarda va en el service y responde 400 al lote entero.

    EL ARREGLO NO TIENE QUE LLEGAR ORDENADO: el servidor lo ordena por recordedAt. pilotId no se acepta —el autor sale del token— y mandarlo se descarta en silencio; tampoco tripId, que va en la URL.

    ATENCIÓN — NO HAY NINGUNA VALIDACIÓN GEOGRÁFICA. Los rangos son los del sistema de coordenadas y NADA MÁS: no se comprueba que el punto caiga cerca de la polyline del viaje, ni dentro de Guatemala, ni que el salto contra el punto anterior sea físicamente posible. No hay telemetría: ni speed, ni heading, ni accuracy, ni battery.

    ATENCIÓN — LA VALIDACIÓN DEL CUERPO CORRE ANTES QUE LAS GUARDAS DEL SERVICE: solo el middleware role:pilot (403) va por delante. Un cuerpo inválido sobre un viaje INEXISTENTE devuelve 422, no 404.
    TEXT,
    required: ['positions'],
    properties: [
        new OA\Property(
            property: 'positions',
            description: 'Los puntos del rastro, de 1 a 1000 (mensajes literales: Las posiciones son obligatorias / Las posiciones deben enviarse como un arreglo / Debes enviar al menos una posición / No puedes enviar más de 1000 posiciones por petición). 1000 puntos al ritmo del piso de 5 s son unos 83 minutos sin señal; con más, la app parte su cola en varios lotes.',
            type: 'array',
            maxItems: 1000,
            minItems: 1,
            items: new OA\Items(
                required: ['latitude', 'longitude', 'recordedAt'],
                properties: [
                    new OA\Property(
                        property: 'latitude',
                        description: 'Latitud del punto, numérica y entre -90 y 90 (mensajes: La latitud del punto N es obligatoria / La latitud del punto N debe ser un número / La latitud del punto N debe estar entre -90 y 90). Se guarda como decimal(10,8) y sale como string de ocho decimales.',
                        type: 'number',
                        format: 'double',
                        maximum: 90,
                        minimum: -90,
                        example: 14.628074,
                    ),
                    new OA\Property(
                        property: 'longitude',
                        description: 'Longitud del punto, numérica y entre -180 y 180 (mensajes: La longitud del punto N es obligatoria / La longitud del punto N debe ser un número / La longitud del punto N debe estar entre -180 y 180). Se guarda como decimal(11,8).',
                        type: 'number',
                        format: 'double',
                        maximum: 180,
                        minimum: -180,
                        example: -90.522554,
                    ),
                    new OA\Property(
                        property: 'recordedAt',
                        description: 'Hora del dispositivo en ISO 8601 con zona (desfase o Z), con o sin milisegundos; se convierte a la zona de la app y se trunca al segundo. No más de 60 s en el futuro ni antes del inicio del viaje (mensajes: La hora del punto N es obligatoria / La hora del punto N debe estar en formato ISO 8601 con zona horaria / La hora del punto N no puede estar en el futuro).',
                        type: 'string',
                        format: 'date-time',
                        example: '2026-10-06T14:32:05-06:00',
                    ),
                ],
                type: 'object',
            ),
        ),
    ],
    type: 'object',
    example: [
        'positions' => [
            ['latitude' => 14.628074, 'longitude' => -90.522554, 'recordedAt' => '2026-10-06T14:32:05-06:00'],
            ['latitude' => 14.628301, 'longitude' => -90.522901, 'recordedAt' => '2026-10-06T20:32:10.123Z'],
        ],
    ],
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
