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
}
