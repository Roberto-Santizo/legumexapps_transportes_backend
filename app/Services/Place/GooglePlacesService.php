<?php

namespace App\Services\Place;

use App\Errors\NotFoundError;
use App\Errors\ServiceUnavailableError;
use App\Interfaces\Place\PlaceServiceInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Override;
use Throwable;

final class GooglePlacesService implements PlaceServiceInterface
{
    private const BASE_URL = 'https://places.googleapis.com/v1/places';

    /**
     * How long the backend waits for the provider before giving up.
     *
     * There are no retries: every call is billed, and a client that received a
     * 503 can search again itself.
     */
    private const TIMEOUT_SECONDS = 10;

    private const PAGE_SIZE = 10;

    private const LANGUAGE_CODE = 'es';

    /**
     * Biases results towards Guatemala without excluding anything else, so a
     * legitimate cross-border search still resolves.
     */
    private const REGION_CODE = 'GT';

    /**
     * Field masks are always explicit: '*' bills at the most expensive tier and
     * ties the response shape to whatever the provider decides to return.
     */
    private const SEARCH_FIELD_MASK = 'places.id,places.formattedAddress';

    private const DETAIL_FIELD_MASK = 'id,formattedAddress,location';

    /**
     * Every provider failure reaches the client as this one message: leaking the
     * provider error would say more than it should about the key and the account.
     */
    private const UNAVAILABLE_MESSAGE = 'El servicio de búsqueda de direcciones no está disponible en este momento. Intenta de nuevo en unos minutos.';

    private const NOT_FOUND_MESSAGE = 'La dirección no existe';

    #[Override]
    public function searchPlaces(string $search): array
    {
        $response = $this->send(fn (): Response => $this->request(self::SEARCH_FIELD_MASK)
            ->post(self::BASE_URL.':searchText', [
                'textQuery' => $search,
                'languageCode' => self::LANGUAGE_CODE,
                'regionCode' => self::REGION_CODE,
                'pageSize' => self::PAGE_SIZE,
            ]));

        if ($response->failed()) {
            throw new ServiceUnavailableError(self::UNAVAILABLE_MESSAGE);
        }

        $body = $this->decode($response);

        /** The provider omits the key entirely when nothing matched. */
        if (! array_key_exists('places', $body)) {
            return [];
        }

        if (! is_array($body['places'])) {
            throw new ServiceUnavailableError(self::UNAVAILABLE_MESSAGE);
        }

        $places = array_slice(array_values($body['places']), 0, self::PAGE_SIZE);

        return array_map(fn (mixed $place): array => $this->toPrediction($place), $places);
    }

    #[Override]
    public function getPlaceById(string $placeId): array
    {
        $response = $this->send(fn (): Response => $this->request(self::DETAIL_FIELD_MASK)
            ->get(self::BASE_URL.'/'.$placeId));

        /**
         * An unknown id answers 404 and a malformed one answers 400; for this API
         * both mean the same thing, so both come out as the same NotFoundError.
         */
        if (in_array($response->status(), [400, 404], true)) {
            throw new NotFoundError(self::NOT_FOUND_MESSAGE);
        }

        if ($response->failed()) {
            throw new ServiceUnavailableError(self::UNAVAILABLE_MESSAGE);
        }

        return $this->toPlace($this->decode($response));
    }

    /**
     * Build the request shared by both calls.
     *
     * The key travels in the header and never in the query string, which would
     * end up in the logs of every proxy in between.
     */
    private function request(string $fieldMask): PendingRequest
    {
        return Http::withHeaders([
            'X-Goog-Api-Key' => (string) config('services.google_places.key'),
            'X-Goog-FieldMask' => $fieldMask,
        ])->timeout(self::TIMEOUT_SECONDS);
    }

    /**
     * Run the call translating any transport failure into a 503.
     *
     * A timeout, a DNS failure or a dropped connection surface as exceptions;
     * an HTTP error status does not, and each caller checks it for itself.
     *
     * @param  callable(): Response  $call
     *
     * @throws ServiceUnavailableError
     */
    private function send(callable $call): Response
    {
        try {
            return $call();
        } catch (Throwable) {
            throw new ServiceUnavailableError(self::UNAVAILABLE_MESSAGE);
        }
    }

    /**
     * Decode a successful body, rejecting anything that is not a JSON object.
     *
     * @return array<string, mixed>
     *
     * @throws ServiceUnavailableError
     */
    private function decode(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new ServiceUnavailableError(self::UNAVAILABLE_MESSAGE);
        }

        return $body;
    }

    /**
     * Map one search result, rejecting the whole call when its shape is wrong.
     *
     * A partially valid list is not filtered and returned short: a changed shape
     * means the provider contract broke, and returning nine of ten results hides
     * it until someone notices the missing addresses.
     *
     * @return array{id: string, formattedAddress: string}
     *
     * @throws ServiceUnavailableError
     */
    private function toPrediction(mixed $place): array
    {
        if (! is_array($place)) {
            throw new ServiceUnavailableError(self::UNAVAILABLE_MESSAGE);
        }

        $id = $place['id'] ?? null;
        $formattedAddress = $place['formattedAddress'] ?? null;

        if (! is_string($id) || $id === '' || ! is_string($formattedAddress) || $formattedAddress === '') {
            throw new ServiceUnavailableError(self::UNAVAILABLE_MESSAGE);
        }

        return [
            'id' => $id,
            'formattedAddress' => $formattedAddress,
        ];
    }

    /**
     * Map the place detail, flattening the provider's nested location.
     *
     * A 200 without coordinates is a broken contract, not a place without a
     * position: it comes out as a 503 rather than as null coordinates that the
     * quote endpoint would reject later with a confusing message.
     *
     * @param  array<string, mixed>  $body
     * @return array{id: string, formattedAddress: string, latitude: float, longitude: float}
     *
     * @throws ServiceUnavailableError
     */
    private function toPlace(array $body): array
    {
        $prediction = $this->toPrediction($body);

        $location = $body['location'] ?? null;

        if (! is_array($location)) {
            throw new ServiceUnavailableError(self::UNAVAILABLE_MESSAGE);
        }

        $latitude = $location['latitude'] ?? null;
        $longitude = $location['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            throw new ServiceUnavailableError(self::UNAVAILABLE_MESSAGE);
        }

        return [
            ...$prediction,
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
        ];
    }
}
