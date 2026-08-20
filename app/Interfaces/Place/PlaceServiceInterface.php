<?php

namespace App\Interfaces\Place;

use App\Errors\NotFoundError;
use App\Errors\ServiceUnavailableError;

interface PlaceServiceInterface
{
    /**
     * Search addresses matching a free text query.
     *
     * The search term arrives already validated: at least 3 and at most 200
     * characters. Results come capped at 10 and their keys are camelCase, since
     * no Eloquent model sits in between.
     *
     * A search with no matches is not an error: the return is an empty list,
     * never null.
     *
     * A provider answer whose shape is not the expected one invalidates the
     * whole call — a partially valid list must not be filtered and returned
     * short, because that hides a broken provider contract.
     *
     * @param  string  $search  Free text, e.g. 'zona 4 guatemala'.
     * @return list<array{id: string, formattedAddress: string}> Empty when there are no matches.
     *
     * @throws ServiceUnavailableError when the provider fails, times out, rejects
     *                                 the credentials or answers an unexpected shape.
     */
    public function searchPlaces(string $search): array;

    /**
     * Resolve the address and coordinates of a single place.
     *
     * The id is an opaque provider string: its shape is not validated, so an
     * unknown id and a malformed one both mean the same thing — there is no
     * such place — and both raise NotFoundError.
     *
     * The four keys are always present or the method throws: coordinates are
     * floats, never null and never nested under a location key.
     *
     * @param  string  $placeId  Opaque provider id, as returned by searchPlaces().
     * @return array{id: string, formattedAddress: string, latitude: float, longitude: float}
     *
     * @throws NotFoundError when the id matches no place or is malformed.
     * @throws ServiceUnavailableError when the provider fails, times out, rejects
     *                                 the credentials or answers an unexpected shape.
     */
    public function getPlaceById(string $placeId): array;

    /**
     * Resolve the road route between two points.
     *
     * The contract sees four numbers and nothing else: it does not know a registered
     * destination exists, it does not know Eloquent and it never queries the database.
     * Which destination this is, and whether it may be used, is decided by the caller
     * before getting here — otherwise a substitute provider would have to learn to
     * resolve active destinations, and that is domain knowledge, not provider
     * capability.
     *
     * Distance and duration come back already converted, rounded to two decimals and
     * as numbers: they are not money and no decimal cast sits in between.
     *
     * The encoded polyline and the decoded pairs are the same line twice, on purpose:
     * the string for the map libraries that consume it directly, the pairs for drawing
     * or measuring without a decoder on the client. The pairs come in `[lat, lng]`,
     * the order the rest of the API speaks.
     *
     * points is never null and never an empty list: a route without points is a broken
     * answer, and it surfaces as a ServiceUnavailableError rather than as a 200 with
     * nothing to draw.
     *
     * @param  float  $originLatitude  Where the trip starts; validated only against its range.
     * @param  float  $originLongitude  Where the trip starts; validated only against its range.
     * @param  float  $destinationLatitude  Coordinates of the registered destination.
     * @param  float  $destinationLongitude  Coordinates of the registered destination.
     * @return array{distanceKilometers: float, durationHours: float, polyline: string, points: list<array{0: float, 1: float}>}
     *
     * @throws NotFoundError when there is no road route between the two points. The
     *                       provider worked: it simply found no way through, so this
     *                       is not a 503 the client should retry.
     * @throws ServiceUnavailableError when the provider fails, times out, rejects
     *                                 the credentials or answers an unexpected shape.
     */
    public function getDirections(
        float $originLatitude,
        float $originLongitude,
        float $destinationLatitude,
        float $destinationLongitude,
    ): array;
}
