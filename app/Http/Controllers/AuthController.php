<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Auth\ConfirmAccountRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\Auth\UserResource;
use App\Interfaces\Auth\AuthServiceInterface;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Auth',
    description: 'Registro, confirmación de cuenta, inicio de sesión y recuperación de contraseña.',
)]
class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/auth/register',
        operationId: 'registerAuth',
        summary: 'Registrar un usuario',
        description: 'Crea una cuenta con rol pilot o carrier. La cuenta nace sin confirmar (emailVerifiedAt en null) y no puede iniciar sesión hasta pasar por /api/auth/confirm-account. Este endpoint NO devuelve token: solo se emite token al hacer login. Junto con el usuario se genera y persiste un código de confirmación de 6 dígitos con una hora de vigencia.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/RegisterRequest'),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Usuario registrado correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 201),
                        new OA\Property(property: 'message', type: 'string', example: 'Usuario registrado correctamente'),
                        new OA\Property(property: 'data', ref: '#/components/schemas/User'),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: el correo ya está registrado, el rol no es pilot ni carrier, la contraseña tiene menos de 8 caracteres o no coincide con password_confirmation',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function register(RegisterRequest $request, AuthServiceInterface $authService)
    {
        try {
            $user = $authService->register($request->validated());

            return ResponseHandler::success(new UserResource($user), 'Usuario registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/auth/confirm-account',
        operationId: 'confirmAccountAuth',
        summary: 'Confirmar una cuenta',
        description: 'Valida el código de 6 dígitos generado en el registro y marca la cuenta como confirmada (emailVerifiedAt deja de ser null). El código expira una hora después de generarse y se elimina al usarse, por lo que no es reutilizable. Mientras la cuenta no esté confirmada, el login responde 403.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ConfirmAccountRequest'),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'La cuenta ha sido confirmada correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'La cuenta ha sido confirmada correctamente'),
                        new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Código inválido, expirado o ya utilizado. El mensaje devuelto es: El código es inválido o ya expiró',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: correo con formato incorrecto o código que no tiene exactamente 6 dígitos',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function confirmAccount(ConfirmAccountRequest $request, AuthServiceInterface $authService)
    {
        try {
            $authService->confirmAccount($request->validated());

            return ResponseHandler::success(null, 'La cuenta ha sido confirmada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/auth/login',
        operationId: 'loginAuth',
        summary: 'Iniciar sesión',
        description: 'Valida las credenciales y devuelve el usuario junto con un token JWT con vigencia de 60 minutos. El token se envía en las peticiones protegidas como: Authorization: Bearer {token}. Solo se renueva llamando a /api/auth/check-status.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/LoginRequest'),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sesión iniciada correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Sesión iniciada correctamente'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                                new OA\Property(
                                    property: 'token',
                                    description: 'Token JWT válido durante 60 minutos.',
                                    type: 'string',
                                    example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.7Hh1c0mFq5Y',
                                ),
                            ],
                            type: 'object',
                        ),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Credenciales incorrectas: el correo no existe o la contraseña no coincide. El mensaje devuelto es: Las credenciales son incorrectas',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 403,
                description: 'Las credenciales son correctas pero la cuenta todavía no ha sido confirmada, por lo que no se emite token. El mensaje devuelto es: La cuenta aún no ha sido confirmada. El cliente debe usar este código para redirigir al usuario a la pantalla de confirmación de cuenta con el código de 6 dígitos.',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: falta el correo o la contraseña, o el correo tiene un formato incorrecto',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function login(LoginRequest $request, AuthServiceInterface $authService)
    {
        try {
            $result = $authService->login($request->validated());

            return ResponseHandler::success([
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
            ], 'Sesión iniciada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/auth/check-status',
        operationId: 'checkStatusAuth',
        summary: 'Verificar la sesión y renovar el token',
        description: 'Devuelve el usuario autenticado y un token nuevo con otros 60 minutos de vigencia; el token usado en la petición queda invalidado. Es el único endpoint que renueva el token: la API no expone /refresh ni /logout. Requiere el header Authorization: Bearer {token}.',
        security: [['bearerAuth' => []]],
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Sesión válida',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Sesión válida'),
                        new OA\Property(
                            property: 'data',
                            properties: [
                                new OA\Property(property: 'user', ref: '#/components/schemas/User'),
                                new OA\Property(
                                    property: 'token',
                                    description: 'Token JWT renovado. Sustituye al enviado en la petición.',
                                    type: 'string',
                                    example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.7Hh1c0mFq5Y',
                                ),
                            ],
                            type: 'object',
                        ),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 401,
                description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
        ],
    )]
    public function checkStatus(AuthServiceInterface $authService)
    {
        try {
            $result = $authService->checkStatus();

            return ResponseHandler::success([
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
            ], 'Sesión válida', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/auth/forgot-password',
        operationId: 'forgotPasswordAuth',
        summary: 'Solicitar código de recuperación de contraseña',
        description: 'Genera y persiste un código de 6 dígitos con una hora de vigencia para la cuenta indicada, reemplazando cualquier código anterior. Responde 200 exista o no el correo en la base de datos: el endpoint no revela qué correos están registrados, por lo que el cliente no debe interpretar el 200 como confirmación de que la cuenta existe.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ForgotPasswordRequest'),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Solicitud procesada. Se devuelve la misma respuesta exista o no la cuenta.',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'Si el correo está registrado recibirás un código de recuperación'),
                        new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: falta el correo o tiene un formato incorrecto',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function forgotPassword(ForgotPasswordRequest $request, AuthServiceInterface $authService)
    {
        try {
            $authService->forgotPassword($request->validated());

            return ResponseHandler::success(null, 'Si el correo está registrado recibirás un código de recuperación', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/auth/reset-password',
        operationId: 'resetPasswordAuth',
        summary: 'Restablecer la contraseña',
        description: 'Valida el código de recuperación de 6 dígitos y sustituye la contraseña de la cuenta. El código expira una hora después de generarse y se elimina al usarse, por lo que no es reutilizable. No se emite token: el usuario debe iniciar sesión con la nueva contraseña.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(ref: '#/components/schemas/ResetPasswordRequest'),
        ),
        tags: ['Auth'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'La contraseña ha sido actualizada correctamente',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'statusCode', type: 'integer', example: 200),
                        new OA\Property(property: 'message', type: 'string', example: 'La contraseña ha sido actualizada correctamente'),
                        new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
                    ],
                    type: 'object',
                ),
            ),
            new OA\Response(
                response: 400,
                description: 'Código inválido, expirado o ya utilizado. El mensaje devuelto es: El código es inválido o ya expiró',
                content: new OA\JsonContent(ref: '#/components/schemas/ApiError'),
            ),
            new OA\Response(
                response: 422,
                description: 'Datos inválidos: correo con formato incorrecto, código que no tiene 6 dígitos o contraseña de menos de 8 caracteres',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function resetPassword(ResetPasswordRequest $request, AuthServiceInterface $authService)
    {
        try {
            $authService->resetPassword($request->validated());

            return ResponseHandler::success(null, 'La contraseña ha sido actualizada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }
}
