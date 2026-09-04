<?php

namespace App\Helpers;

use App\Errors\ApiException;
use Illuminate\Http\Resources\Json\JsonResource;

class ResponseHandler
{
    /**
     * Encoding options applied to every envelope of the API.
     *
     * The default of json_encode() escapes forward slashes, so a public file URL
     * travels as https:\/\/bucket... in the raw body; any JSON parser undoes it,
     * but reading the response by hand is needless friction. Unicode is left
     * unescaped for the same reason: the messages are in Spanish.
     */
    private const ENCODING_OPTIONS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public static function success(mixed $data, string $message, int $statusCode)
    {
        if ($data instanceof JsonResource) {
            $data = $data->resolve();
        }

        $response = [
            'statusCode' => $statusCode,
            'message' => $message,
        ];

        if (is_array($data) && isset($data['data']) && count($data) > 1) {
            $response['data'] = $data['data'];

            unset($data['data']);

            $response = array_merge($response, $data);
        } else {
            $response['data'] = $data;
        }

        return response()->json($response, $statusCode, [], self::ENCODING_OPTIONS);
    }

    public static function error(\Throwable $error)
    {
        $statusCode = $error instanceof ApiException ? $error->getStatusCode() : 500;

        return response()->json([
            'statusCode' => $statusCode,
            'message' => $error->getMessage(),
            'data' => null,
        ], $statusCode, [], self::ENCODING_OPTIONS);
    }
}
