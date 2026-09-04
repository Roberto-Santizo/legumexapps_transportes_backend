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
        description: <<<'TEXT'
        Crea una cuenta con rol pilot o carrier. La cuenta nace sin confirmar (emailVerifiedAt en null) y no puede iniciar sesión hasta pasar por /api/auth/confirm-account. Este endpoint NO devuelve token: solo se emite token al hacer login. Junto con el usuario se genera y persiste un código de confirmación de 6 dígitos con una hora de vigencia.

        ATENCIÓN — EL CUERPO VA EN multipart/form-data, NO EN JSON. Un piloto debe adjuntar dpi y license, las fotos del anverso de su DPI y de su licencia, y un archivo no viaja en un cuerpo JSON. Es un CAMBIO INCOMPATIBLE SIN PERIODO DE GRACIA: el alta de piloto pasa de cuatro campos a seis y la que antes devolvía 201 con cuatro ahora devuelve 422.

        Las dos fotos son OBLIGATORIAS SOLO PARA role=pilot y van siempre juntas: mandar una sola es 422. Un carrier no sube nada, y si las manda se DESCARTAN EN SILENCIO con 201, sin crear fila ni subir archivos.

        Las fotos se suben una sola vez, aquí, y no se pueden reemplazar después: no existe ningún endpoint que las acepte. La respuesta 201 ya trae dpiImage y licenseImage como URLs absolutas y PÚBLICAS —quien tenga el enlace las abre sin token, igual que la imagen de un vehículo—, o en null cuando no hay documentos.

        Nada bloquea a un piloto por no tener documentos: los registrados antes de esta versión siguen haciendo login, uniéndose a una empresa y operando viajes con normalidad. Tampoco los revisa nadie: no hay verificación ni aprobación, y el único requisito para activar la cuenta sigue siendo el código de 6 dígitos.
        TEXT,
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(ref: '#/components/schemas/RegisterRequest'),
            ),
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
                description: 'Datos inválidos: el correo ya está registrado, el rol no es pilot ni carrier, la contraseña tiene menos de 8 caracteres o no coincide con password_confirmation, o el rol es pilot y falta alguna de las dos fotos (dpi, license), no es una imagen, no es jpg/jpeg/png o supera los 3 MB',
                content: new OA\JsonContent(ref: '#/components/schemas/ValidationError'),
            ),
        ],
    )]
    public function register(RegisterRequest $request, AuthServiceInterface $authService)
    {
        try {
            $user = $authService->register($request->validated());

            return ResponseHandler::success(new UserResource($user), 'Hemos enviado instrucciones a tu correo electronico', 201);
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

            return ResponseHandler::success(null, 'La cuenta ha sido confirmada correctamente, inicie sesión.', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Post(
        path: '/api/auth/login',
        operationId: 'loginAuth',
        summary: 'Iniciar sesión',
        description: 'Valida las credenciales y devuelve el usuario junto con dos tokens JWT: token, con vigencia de 60 minutos, y refreshToken, con vigencia de 14 días. Cualquiera de los dos se envía en las peticiones protegidas como: Authorization: Bearer {token}. La sesión se prorroga llamando a /api/auth/check-status, que emite un par nuevo; la API no expone /refresh ni /logout. ADVERTENCIA: el refreshToken no está restringido a la renovación, es un token JWT corriente que autentica cualquier ruta protegida durante sus 14 días, y no existe forma de revocarlo antes de que expire.',
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
                                    description: 'Token JWT de acceso, válido durante 60 minutos. Lleva el claim tokenType con el valor access.',
                                    type: 'string',
                                    example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.7Hh1c0mFq5Y',
                                ),
                                new OA\Property(
                                    property: 'refreshToken',
                                    description: 'Token JWT de refresco, válido durante 14 días. Lleva el claim tokenType con el valor refresh y los mismos claims de usuario que el de acceso. Su uso previsto es renovar la sesión en /api/auth/check-status, pero nada lo restringe a eso: autentica cualquier ruta protegida igual que el token de acceso.',
                                    type: 'string',
                                    example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.9Kk2d1nGr6Z',
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
                'refreshToken' => $result['refreshToken'],
            ], 'Sesión iniciada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    #[OA\Get(
        path: '/api/auth/check-status',
        operationId: 'checkStatusAuth',
        summary: 'Verificar la sesión y renovar los tokens',
        description: 'Devuelve el usuario autenticado y un par de tokens nuevo: token con otros 60 minutos y refreshToken con otros 14 días completos, no el remanente del que llegó. Admite en el header cualquiera de los dos tokens, porque ambos llevan la misma firma. Es el único endpoint que renueva la sesión: la API no expone /refresh ni /logout. Encadenar llamadas antes de que expire el refreshToken mantiene la sesión viva indefinidamente. Los tokens enviados en la petición NO quedan invalidados: siguen siendo válidos hasta su propia expiración. Requiere el header Authorization: Bearer {token}.',
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
                                    description: 'Token JWT de acceso renovado, válido durante 60 minutos. Sustituye al enviado en la petición.',
                                    type: 'string',
                                    example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.7Hh1c0mFq5Y',
                                ),
                                new OA\Property(
                                    property: 'refreshToken',
                                    description: 'Token JWT de refresco renovado, válido durante 14 días completos contados desde esta respuesta. Sustituye al anterior y, como él, autentica cualquier ruta protegida además de renovar la sesión aquí.',
                                    type: 'string',
                                    example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.9Kk2d1nGr6Z',
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
            new OA\Response(
                response: 403,
                description: 'El token es válido pero la cuenta todavía no ha sido confirmada. El mensaje devuelto es: La cuenta aún no ha sido confirmada',
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
                'refreshToken' => $result['refreshToken'],
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
