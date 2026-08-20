<?php

namespace Tests\Doubles;

use App\Errors\NotFoundError;
use App\Errors\ServiceUnavailableError;
use App\Interfaces\Place\PlaceServiceInterface;
use App\Services\Place\PolylineDecoder;
use Override;

/**
 * A place contract implementation that never speaks HTTP.
 *
 * It exists to prove the substitutability the spec asks for: the whole Feature suite
 * of the domain has to pass with this bound in the container, without Google existing
 * and without a single outgoing request.
 *
 * Its addresses are fixed and its failure mode is a flag, so the error paths get
 * exercised without decoding a provider payload.
 */
class InMemoryPlaceService implements PlaceServiceInterface
{
    /**
     * The three addresses the double knows, keyed by place id.
     *
     * @var array<string, array{id: string, formattedAddress: string, latitude: float, longitude: float}>
     */
    private const PLACES = [
        'ChIJzona4' => [
            'id' => 'ChIJzona4',
            'formattedAddress' => '5a Avenida 12-38, Zona 4, Ciudad de Guatemala',
            'latitude' => 14.6248,
            'longitude' => -90.5152,
        ],
        'ChIJzona10' => [
            'id' => 'ChIJzona10',
            'formattedAddress' => '16 Calle 2-00, Zona 10, Ciudad de Guatemala',
            'latitude' => 14.5906,
            'longitude' => -90.5089,
        ],
        'ChIJcoban' => [
            'id' => 'ChIJcoban',
            'formattedAddress' => '3a Calle 1-11, Cobán, Alta Verapaz',
            'latitude' => 15.4711,
            'longitude' => -90.3711,
        ],
    ];

    /**
     * A short encoded route through Guatemala, the same line every call returns.
     *
     * The double does not draw a line between the points it was given: it answers a
     * fixed one, so the Feature suite can assert against a known value. That the
     * coordinates actually reach the provider in the right order is checked where it
     * can be checked for real, against the outgoing request in GooglePlacesServiceTest.
     */
    private const POLYLINE = '_lgxA~vmgPrIoAzmE~}A~j`Crzp@';

    private const DISTANCE_KILOMETERS = 104.32;

    private const DURATION_HOURS = 1.75;

    /**
     * @param  bool  $failing  Makes every method fail, as a provider that is down would.
     * @param  bool  $routeless  Makes getDirections() find no road route, as an origin
     *                           in the middle of the ocean would.
     */
    public function __construct(
        private readonly bool $failing = false,
        private readonly bool $routeless = false,
    ) {}

    #[Override]
    public function searchPlaces(string $search): array
    {
        $this->failWhenDown();

        /** Coincidencia por subcadena sobre la dirección, insensible a mayúsculas. */
        $matches = array_filter(
            self::PLACES,
            fn (array $place): bool => str_contains(
                mb_strtolower($place['formattedAddress']),
                mb_strtolower($search),
            ),
        );

        return array_values(array_map(fn (array $place): array => [
            'id' => $place['id'],
            'formattedAddress' => $place['formattedAddress'],
        ], $matches));
    }

    #[Override]
    public function getPlaceById(string $placeId): array
    {
        $this->failWhenDown();

        if (! array_key_exists($placeId, self::PLACES)) {
            throw new NotFoundError('La dirección no existe');
        }

        return self::PLACES[$placeId];
    }

    #[Override]
    public function getDirections(
        float $originLatitude,
        float $originLongitude,
        float $destinationLatitude,
        float $destinationLongitude,
    ): array {
        $this->failWhenDown();

        /** El proveedor funcionó y no hay camino: 404, no 503. */
        if ($this->routeless) {
            throw new NotFoundError('No se encontró una ruta hacia el destino');
        }

        return self::route();
    }

    /**
     * The failure every provider outage looks like from the outside.
     *
     * @throws ServiceUnavailableError
     */
    private function failWhenDown(): void
    {
        if ($this->failing) {
            throw new ServiceUnavailableError('El servicio de búsqueda de direcciones no está disponible en este momento. Intenta de nuevo en unos minutos.');
        }
    }

    /**
     * The ids the double knows, so a test can pick one without repeating the literal.
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(self::PLACES);
    }

    /**
     * The route every successful call answers, so a test can assert against it without
     * repeating the literals.
     *
     * The pairs are decoded with the real decoder rather than hardcoded, so the string
     * and the points can never drift apart.
     *
     * @return array{distanceKilometers: float, durationHours: float, polyline: string, points: list<array{0: float, 1: float}>}
     */
    public static function route(): array
    {
        return [
            'distanceKilometers' => self::DISTANCE_KILOMETERS,
            'durationHours' => self::DURATION_HOURS,
            'polyline' => self::POLYLINE,
            'points' => PolylineDecoder::decode(self::POLYLINE),
        ];
    }
}
