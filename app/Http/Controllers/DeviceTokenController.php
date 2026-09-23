<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\DeviceToken\StoreDeviceTokenRequest;
use App\Http\Resources\DeviceToken\DeviceTokenResource;
use App\Interfaces\DeviceToken\DeviceTokenServiceInterface;

class DeviceTokenController extends Controller
{
    public function store(StoreDeviceTokenRequest $request, DeviceTokenServiceInterface $deviceTokenService)
    {
        try {
            $result = $deviceTokenService->registerToken(auth('api')->user(), $request->validated());

            /** 201 solo cuando nace una fila; refrescar o reasignar es 200. */
            return $result['created']
                ? ResponseHandler::success(new DeviceTokenResource($result['token']), 'Token de dispositivo registrado correctamente', 201)
                : ResponseHandler::success(new DeviceTokenResource($result['token']), 'Token de dispositivo actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(string $token, DeviceTokenServiceInterface $deviceTokenService)
    {
        try {
            $deviceToken = $deviceTokenService->deleteToken(auth('api')->user(), $token);

            return ResponseHandler::success(new DeviceTokenResource($deviceToken), 'Token de dispositivo eliminado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }
}
