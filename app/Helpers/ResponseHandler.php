<?php

namespace App\Helpers;

use App\Errors\ApiException;
use Illuminate\Http\Resources\Json\JsonResource;

class ResponseHandler
{
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

        return response()->json($response, $statusCode);
    }

    public static function error(\Throwable $error)
    {
        $statusCode = $error instanceof ApiException ? $error->getStatusCode() : 500;

        return response()->json([
            'statusCode' => $statusCode,
            'message' => $error->getMessage(),
            'data' => null,
        ], $statusCode);
    }
}
