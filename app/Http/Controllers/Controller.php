<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    description: 'API interna de Legumex Transportes. Todas las respuestas usan el sobre estándar de ResponseHandler: statusCode, message, data.',
    title: 'Legumex Transportes API',
)]
#[OA\Server(
    url: L5_SWAGGER_CONST_HOST,
    description: 'Servidor de la API',
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    description: 'Token JWT emitido por el login. Se envía como: Authorization: Bearer {token}',
    bearerFormat: 'JWT',
    scheme: 'bearer',
)]
#[OA\Schema(
    schema: 'ApiError',
    description: 'Sobre de error devuelto por ResponseHandler::error().',
    properties: [
        new OA\Property(property: 'statusCode', type: 'integer', example: 404),
        new OA\Property(property: 'message', type: 'string', example: 'El recurso no existe'),
        new OA\Property(property: 'data', type: 'object', nullable: true, example: null),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ValidationError',
    description: 'Errores de validación devueltos por los FormRequests.',
    properties: [
        new OA\Property(property: 'message', type: 'string', example: 'El campo nombre es obligatorio'),
        new OA\Property(
            property: 'errors',
            type: 'object',
            additionalProperties: new OA\AdditionalProperties(type: 'array', items: new OA\Items(type: 'string')),
        ),
    ],
    type: 'object',
)]
abstract class Controller
{
    //
}
