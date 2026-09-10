<?php

namespace App\Services\TripTimeout;

/**
 * Great circle distance between two coordinates, in metres.
 *
 * It lives next to the service because deciding whether a truck moved is the only
 * thing the project measures distances for. It is final and static because it holds
 * no state and has no dependencies, exactly like PolylineDecoder (SPEC 16) and
 * Zone::pairsToWkt(): an algorithm that is tested whole, without a database and
 * without the network.
 *
 * Haversine, not Vincenty and not PostGIS `ST_Distance`: at the five metre scale this
 * domain cares about, the error of the spherical model is measured in millimetres,
 * and the table has no geographic column to hand to PostGIS anyway.
 */
final class DistanceCalculator
{
    /** Radio medio de la Tierra en metros: la esfera basta a esta escala. */
    private const EARTH_RADIUS_METERS = 6_371_000;

    /**
     * Distancia a partir de la cual se considera que el camión se movió.
     *
     * The single place the threshold lives: raising it to 10 or 15 metres once real
     * GPS noise is on the table is a one line change with no migration behind it.
     */
    public const MOVEMENT_THRESHOLD_METERS = 5;

    /**
     * Distance between two `[lat, lng]` coordinates, in metres.
     *
     * Never throws and never returns a negative number: two identical coordinates are
     * exactly `0.0`, and the order of the arguments does not change the result.
     */
    public static function metersBetween(
        float $fromLatitude,
        float $fromLongitude,
        float $toLatitude,
        float $toLongitude,
    ): float {
        $fromLatitudeRadians = deg2rad($fromLatitude);
        $toLatitudeRadians = deg2rad($toLatitude);

        $latitudeDelta = deg2rad($toLatitude - $fromLatitude);
        $longitudeDelta = deg2rad($toLongitude - $fromLongitude);

        $haversine = sin($latitudeDelta / 2) ** 2
            + cos($fromLatitudeRadians) * cos($toLatitudeRadians) * sin($longitudeDelta / 2) ** 2;

        /**
         * `min(1, ...)` guards the arc sine against a haversine that floating point error
         * pushed a hair above 1 for two antipodal points: without it the result would be
         * NAN instead of half the circumference.
         */
        return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($haversine)));
    }
}
