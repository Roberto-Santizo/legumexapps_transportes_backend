<?php

namespace App\Services\Place;

/**
 * Encoder for the provider's encoded polyline format: the exact mirror of
 * PolylineDecoder::decode().
 *
 * It lives next to the decoder because the format is the provider's, not the
 * project's, and knowing how a route is encoded does not leave this directory. It is
 * final and static because it holds no state and has no dependencies. Its only
 * consumer is TripService::finish(), which turns the trip_positions trail into the
 * same format the frontend already decodes for the planned route.
 */
final class PolylineEncoder
{
    /** Coordinates travel as integers scaled by 1e5, i.e. five decimal places. */
    private const PRECISION = 100000;

    /** Five bits of payload per character; the sixth flags that another one follows. */
    private const CHUNK_MASK = 0x1F;

    private const CONTINUATION_BIT = 0x20;

    /** Every character is offset by 63 so the result stays printable ASCII. */
    private const ASCII_OFFSET = 63;

    /**
     * Encode `[lat, lng]` pairs into the provider's polyline format.
     *
     * The pairs come in the same `[lat, lng]` order the rest of the API speaks, the
     * one PolylineDecoder::decode() returns. Each coordinate is rounded to five
     * decimals before computing deltas, so anything finer — trip_positions stores
     * eight — is lost here, about a metre, and never comes back on decode.
     *
     * A pure function of the format: an empty list encodes to the empty string,
     * which is the valid encoding of zero points. Turning "no points" into `null`
     * is the domain's call, not the encoder's.
     *
     * Invariant: `PolylineDecoder::decode(self::encode($points)) === $points` for any
     * list already rounded to five decimals.
     *
     * @param  list<array{0: float, 1: float}>  $points
     */
    public static function encode(array $points): string
    {
        $polyline = '';
        $latitude = 0;
        $longitude = 0;

        foreach ($points as [$pointLatitude, $pointLongitude]) {
            $scaledLatitude = (int) round($pointLatitude * self::PRECISION);
            $scaledLongitude = (int) round($pointLongitude * self::PRECISION);

            $polyline .= self::writeValue($scaledLatitude - $latitude);
            $polyline .= self::writeValue($scaledLongitude - $longitude);

            $latitude = $scaledLatitude;
            $longitude = $scaledLongitude;
        }

        return $polyline;
    }

    /**
     * Write one delta as a zigzag-encoded run of 5-bit chunks.
     *
     * Zigzag: the lowest bit carries the sign, the rest is the magnitude — the inverse
     * of what PolylineDecoder::readValue() undoes.
     */
    private static function writeValue(int $delta): string
    {
        $value = $delta < 0 ? ~($delta << 1) : $delta << 1;
        $encoded = '';

        while ($value >= self::CONTINUATION_BIT) {
            $encoded .= chr((self::CONTINUATION_BIT | ($value & self::CHUNK_MASK)) + self::ASCII_OFFSET);
            $value >>= 5;
        }

        return $encoded.chr($value + self::ASCII_OFFSET);
    }
}
