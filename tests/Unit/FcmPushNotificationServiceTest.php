<?php

use App\Interfaces\PushNotification\PushNotificationServiceInterface;
use App\Providers\PushNotification\PushNotificationProvider;
use App\Services\PushNotification\FcmPushNotificationService;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\QuotaExceeded;
use Kreait\Firebase\Exception\Messaging\ServerError;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;
use Tests\Doubles\InMemoryPushNotificationService;

/*
|--------------------------------------------------------------------------
| FcmPushNotificationService
|--------------------------------------------------------------------------
|
| La tercera llamada saliente del proyecto. Messaging de kreait se mockea entero:
| la suite no tiene FIREBASE_CREDENTIALS y no sale a la red. Lo que se comprueba es
| el troceo en lotes de 500 y qué fallos por token cuentan como token muerto.
|
*/

/**
 * Build the multicast report FCM would answer for a batch.
 *
 * @param  list<string>  $tokens
 * @param  array<string, MessagingException>  $failures  Error per token; the rest succeed.
 */
function fcmReport(array $tokens, array $failures = []): MulticastSendReport
{
    return MulticastSendReport::withItems(array_map(
        function (string $token) use ($failures): SendReport {
            $target = MessageTarget::with(MessageTarget::TOKEN, $token);

            return array_key_exists($token, $failures)
                ? SendReport::failure($target, $failures[$token])
                : SendReport::success($target, ['name' => "projects/test/messages/{$token}"]);
        },
        $tokens,
    ));
}

/**
 * @return list<string>
 */
function fcmTokens(int $count): array
{
    return array_map(fn (int $index): string => "token-{$index}", range(1, $count));
}

it('parte 1 200 tokens en tres llamadas multicast de 500, 500 y 200', function (): void {
    $batchSizes = [];

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')
        ->times(3)
        ->andReturnUsing(function (CloudMessage $message, array $tokens) use (&$batchSizes): MulticastSendReport {
            $batchSizes[] = count($tokens);

            return fcmReport($tokens);
        });

    $rejected = new FcmPushNotificationService($messaging)
        ->send(fcmTokens(1200), 'Viaje iniciado', 'Orden ABC', ['type' => 'trip.started', 'tripId' => '1']);

    expect($batchSizes)->toBe([500, 500, 200])
        ->and($rejected)->toBe([]);
});

it('no llama a Messaging con una lista vacía y devuelve []', function (): void {
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldNotReceive('sendMulticast');

    expect(new FcmPushNotificationService($messaging)->send([], 'Título', 'Cuerpo', []))->toBe([]);
});

it('manda título, cuerpo y data en el mensaje', function (): void {
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')
        ->once()
        ->withArgs(function (CloudMessage $message, array $tokens): bool {
            $payload = $message->jsonSerialize();

            return $tokens === ['token-1']
                && $payload['notification'] === ['title' => 'Nuevo viaje asignado', 'body' => 'Orden ABC · PUERTO']
                && $payload['data'] === ['type' => 'trip.assigned', 'tripId' => '42'];
        })
        ->andReturn(fcmReport(['token-1']));

    new FcmPushNotificationService($messaging)
        ->send(['token-1'], 'Nuevo viaje asignado', 'Orden ABC · PUERTO', ['type' => 'trip.assigned', 'tripId' => '42']);
});

it('devuelve solo los tokens UNREGISTERED o INVALID_ARGUMENT', function (): void {
    $tokens = ['vivo', 'no-registrado', 'invalido', 'sin-cuota', 'error-interno'];

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')->once()->andReturn(fcmReport($tokens, [
        'no-registrado' => new NotFound('Requested entity was not found.'),
        'invalido' => new InvalidMessage('The registration token is not a valid FCM registration token'),
        'sin-cuota' => new QuotaExceeded('Quota exceeded'),
        'error-interno' => new ServerError('Internal error'),
    ]));

    $rejected = new FcmPushNotificationService($messaging)->send($tokens, 'Título', 'Cuerpo', []);

    expect($rejected)->toBe(['no-registrado', 'invalido']);
});

it('junta los tokens rechazados de todos los lotes', function (): void {
    $tokens = fcmTokens(501);

    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')->twice()->andReturnUsing(
        fn (CloudMessage $message, array $batch): MulticastSendReport => fcmReport($batch, array_intersect_key(
            ['token-3' => new NotFound('Requested entity was not found.'), 'token-501' => new NotFound('Requested entity was not found.')],
            array_flip($batch),
        )),
    );

    expect(new FcmPushNotificationService($messaging)->send($tokens, 'Título', 'Cuerpo', []))
        ->toBe(['token-3', 'token-501']);
});

it('deja propagar el fallo de una llamada entera', function (): void {
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')->once()->andThrow(new ServerError('Unavailable'));

    new FcmPushNotificationService($messaging)->send(['token-1'], 'Título', 'Cuerpo', []);
})->throws(ServerError::class);

it('resuelve el contrato al doble en memoria en toda la suite', function (): void {
    expect(app(PushNotificationServiceInterface::class))->toBeInstanceOf(InMemoryPushNotificationService::class);
});

it('el provider bindea FCM sin resolver kreait hasta el primer envío', function (): void {
    app()->forgetInstance(PushNotificationServiceInterface::class);
    new PushNotificationProvider(app())->register();

    $service = app(PushNotificationServiceInterface::class);

    expect($service)->toBeInstanceOf(FcmPushNotificationService::class)
        ->and(new ReflectionClass(FcmPushNotificationService::class)->isUninitializedLazyObject($service))->toBeTrue();
});
