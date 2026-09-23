<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\Info(
    version: '1.0.0',
    description: <<<'TEXT'
    API interna de Legumex Transportes. Todas las respuestas usan el sobre estándar de ResponseHandler: statusCode, message, data.

    MATRIZ DE ROLES (siete, en el claim role del token). Solo pilot y carrier se autoregistran; el resto se da de alta en base de datos.
    - administrator: toda la gestión, sin suplantar identidades. No hace lo que es acto de una empresa o de un piloto concretos: /assignment, /start, /finish, POST /positions, /trips/current, los /confirm, /carriers/join, /carriers/me* ni POST /carriers. Sí registra vehículos (eligiendo carrier_id), cargas y viáticos en cualquier viaje asignado.
    - manager: lee todo, no escribe nada. Ve todas las empresas.
    - carrier y pilot: sin cambios, acotados a su empresa y a sus viajes.
    - export: todo lo de viajes —alta, edición de datos (nunca la tripulación), baja y seguimiento—, CRUD de clientes, navieras, destinos y puntos de partida, tablero, asistente y lectura de vehículos y pilotos. Ve todos los viajes y todas las empresas.
    - user: SOLO viajes, en lectura: listado, detalle, posiciones, paradas, cargas, viáticos, costo y canal websocket, de cualquier viaje. Cualquier otra ruta es 403.
    - shipment: como user pero sin nada de dinero: /trips/{trip}/expenses y /trips/{trip}/cost son 403 y totalExpensesAmount del detalle vale siempre "0.00".
    Donde una descripción de endpoint hable de «los cuatro roles» o de «cualquier autenticado», léase: todos salvo user y shipment en los catálogos, places, tarifas y precios; todos los roles en la lectura de viajes.
    TEXT,
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
