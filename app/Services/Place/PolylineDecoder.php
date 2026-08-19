<?php

namespace App\Services\Place;

/**
 * Decoder for the provider's encoded polyline format.
 *
 * It lives next to the provider service because the format is the provider's, not
 * the project's: knowing how a route is encoded is provider knowledge and does not
 * leave this directory. It is final and static because it holds no state and has no
 * dependencies, exactly like Zone::pairsToWkt().
 */
final class PolylineDecoder
{
    /** Coordinates travel as integers scaled by 1e5, i.e. five decimal places. */
    private const PRECISION = 100000;

    private const DECIMALS = 5;

    /** Five bits of payload per character; the sixth flags that another one follows. */
    private const CHUNK_MASK = 0x1F;

    private const CONTINUATION_BIT = 0x20;

    /** Every character is offset by 63 so the result stays printable ASCII. */
    private const ASCII_OFFSET = 63;

    /**
     * Decode an encoded polyline into `[lat, lng]` pairs.
     *
     * The pairs come out in the same `[lat, lng]` order the rest of the API speaks,
     * the one zones already use. That the provider happens to encode latitude first
     * is a coincidence, not the contract.
     *
     * Never throws: an empty string returns an empty list, and a truncated one stops
     * at the last complete pair instead of inventing a point with a zero delta.
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function decode(string $polyline): array
    {
        $points = [];
        $index = 0;
        $length = strlen($polyline);
        $latitude = 0;
        $longitude = 0;

        while ($index < $length) {
            $latitudeDelta = self::readValue($polyline, $index, $length);
            $longitudeDelta = self::readValue($polyline, $index, $length);

            if ($latitudeDelta === null || $longitudeDelta === null) {
                break;
            }

            $latitude += $latitudeDelta;
            $longitude += $longitudeDelta;

            $points[] = [
                round($latitude / self::PRECISION, self::DECIMALS),
                round($longitude / self::PRECISION, self::DECIMALS),
            ];
        }

        return $points;
    }

    /**
     * Read one zigzag-encoded delta, advancing the cursor past it.
     *
     * Returns null when the string runs out mid value, which is the only way the
     * caller can tell a truncated tail from a legitimate delta of zero.
     */
    private static function readValue(string $polyline, int &$index, int $length): ?int
    {
        $result = 0;
        $shift = 0;

        do {
            if ($index >= $length) {
                return null;
            }

            $chunk = ord($polyline[$index]) - self::ASCII_OFFSET;
            $index++;

            $result |= ($chunk & self::CHUNK_MASK) << $shift;
            $shift += 5;
        } while ($chunk >= self::CONTINUATION_BIT);

        /** Zigzag: the lowest bit carries the sign, the rest is the magnitude. */
        return ($result & 1) !== 0 ? ~($result >> 1) : $result >> 1;
    }
}
