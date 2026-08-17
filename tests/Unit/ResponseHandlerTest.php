<?php

use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotAcceptable;
use App\Errors\NotFoundError;
use App\Errors\ServiceUnavailableError;
use App\Errors\UnauthorizedError;
use App\Helpers\ResponseHandler;

/*
|--------------------------------------------------------------------------
| Mapeo de excepciones a status
|--------------------------------------------------------------------------
|
| ResponseHandler::error() no lleva una tabla de clases: pregunta getStatusCode() a
| cualquier ApiException y cae a 500 con el resto. ServiceUnavailableError es la
| primera 5xx del proyecto, así que se comprueba que entra en ese mecanismo sin
| romper el status de las cinco que ya existían.
|
*/

it('responde 503 con el sobre estándar ante un ServiceUnavailableError', function () {
    $response = ResponseHandler::error(new ServiceUnavailableError('El servicio de direcciones no está disponible en este momento.'));

    expect($response->getStatusCode())->toBe(503);

    expect($response->getData(true))->toBe([
        'statusCode' => 503,
        'message' => 'El servicio de direcciones no está disponible en este momento.',
        'data' => null,
    ]);
});

it('mantiene el status de las excepciones que ya existían', function (string $error, int $statusCode) {
    $response = ResponseHandler::error(new $error('mensaje'));

    expect($response->getStatusCode())->toBe($statusCode);
})->with([
    'bad request' => [BadRequestError::class, 400],
    'unauthorized' => [UnauthorizedError::class, 401],
    'forbidden' => [ForbiddenError::class, 403],
    'not found' => [NotFoundError::class, 404],
    'not acceptable' => [NotAcceptable::class, 406],
]);

it('cae a 500 con cualquier throwable que no sea ApiException', function () {
    $response = ResponseHandler::error(new RuntimeException('cualquier cosa'));

    expect($response->getStatusCode())->toBe(500);
});
