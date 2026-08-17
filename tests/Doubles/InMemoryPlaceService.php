<?php

namespace Tests\Doubles;

use App\Errors\NotFoundError;
use App\Errors\ServiceUnavailableError;
use App\Interfaces\Place\PlaceServiceInterface;
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
     * @param  bool  $failing  Makes both methods fail, as a provider that is down would.
     */
    public function __construct(private readonly bool $failing = false) {}

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
}
