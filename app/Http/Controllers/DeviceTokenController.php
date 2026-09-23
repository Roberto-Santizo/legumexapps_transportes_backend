<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\DeviceToken\StoreDeviceTokenRequest;
use App\Http\Resources\DeviceToken\DeviceTokenResource;
use App\Interfaces\DeviceToken\DeviceTokenServiceInterface;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Device Tokens',
    description: <<<'TEXT'
    Tokens FCM (Android e iOS) de los dispositivos del usuario, guardados para notificaciones push. DOS ENDPOINTS Y NINGUNO MÁS, los dos con token JWT (Authorization: Bearer {token}); sin él la respuesta es 401 con «El token de sesión no es válido o ha expirado».

    SIN role: NI carrier.required: valen para los siete roles, y un carrier o un pilot sin empresa también pueden registrar su teléfono.

    ATENCIÓN — TODAVÍA NO SE ENVÍA NINGUNA NOTIFICACIÓN. Este dominio solo guarda los tokens. No hay GET para listarlos ni endpoint de logout: al cerrar sesión el móvil debe llamar al DELETE.
    TEXT,
)]
class DeviceTokenController extends Controller
{
    #[OA\Post(
        path: '/api/device-tokens',
        operationId: 'storeDeviceToken',
        summary: 'Registrar el token FCM del dispositivo',
        description: <<<'TEXT'
        Registra el token FCM del dispositivo a nombre del usuario autenticado. ES IDEMPOTENTE y el código depende de quién tenga ya el token:

        1) Nadie lo tiene → crea la fila y responde 201 «Token de dispositivo registrado correctamente».
        2) Ya es del usuario autenticado → actualiza platform y lastSeenAt y responde 200 «Token de dispositivo actualizado correctamente». No crea una segunda fila.
        3) Es de OTRO usuario → lo REASIGNA al autenticado y responde 200 con el mismo mensaje. El usuario anterior deja de estar ligado a ese teléfono.

        El móvil puede llamarlo en cada arranque o cada vez que FCM rote el token: repetirlo no duplica nada.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/StoreDeviceTokenRequest'),
        ),
        security: [['bearerAuth' => []]],
        tags: ['Device Tokens'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Token nuevo registrado a nombre del usuario autenticado.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Token de dispositivo registrado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/DeviceToken'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 200,
                description: 'El token ya existía (propio o de otro usuario): se refrescó o se reasignó al autenticado.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Token de dispositivo actualizado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/DeviceToken'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'token o platform ausentes o inválidos, con el formato propio de Laravel {message, errors}. Mensajes literales: El token de dispositivo es obligatorio / El token de dispositivo debe ser un texto / El token de dispositivo no puede superar los 512 caracteres / El token de dispositivo no puede contener espacios / La plataforma es obligatoria / La plataforma seleccionada no es válida.',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
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

    #[OA\Delete(
        path: '/api/device-tokens/{token}',
        operationId: 'destroyDeviceToken',
        summary: 'Retirar el token FCM del dispositivo',
        description: <<<'TEXT'
        Borra FÍSICAMENTE uno de los tokens del usuario autenticado y devuelve la fila borrada. Es lo que el móvil debe llamar al cerrar sesión.

        ATENCIÓN — {token} ES EL TOKEN FCM, NO EL id DE LA FILA, y debe ir codificado con encodeURIComponent.

        ATENCIÓN — 404 TANTO SI NO EXISTE COMO SI ES DE OTRO USUARIO, con el mismo mensaje, para no revelar a quién pertenece. El token ajeno no se toca.
        TEXT,
        security: [['bearerAuth' => []]],
        tags: ['Device Tokens'],
        parameters: [
            new OA\Parameter(
                name: 'token',
                description: 'El token FCM del dispositivo, codificado con encodeURIComponent.',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'string', example: 'dXk3:APA91bHfake_token-123'),
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Token borrado. data es la fila tal como estaba antes de borrarla.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Token de dispositivo eliminado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/DeviceToken'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 404,
                description: 'El token no existe o es de otro usuario (indistinguibles a propósito). El mensaje devuelto es: El token de dispositivo no existe',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
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
