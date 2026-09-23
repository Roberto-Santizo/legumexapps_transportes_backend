<?php

use App\Enums\TripNotificationType;
use App\Enums\UserRole;
use App\Interfaces\PushNotification\PushNotificationServiceInterface;
use App\Interfaces\TripNotification\TripNotificationServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Services\TripNotification\TripNotificationService;
use Illuminate\Support\Facades\Log;
use Tests\Doubles\InMemoryPushNotificationService;

/**
 * Resolve the service through the container, which checks the Provider binding too.
 */
function tripNotificationService(): TripNotificationServiceInterface
{
    return app(TripNotificationServiceInterface::class);
}

/**
 * The global double of tests/Pest.php, to read what reached the provider.
 */
function tripNotificationPush(): InMemoryPushNotificationService
{
    return app(PushNotificationServiceInterface::class);
}

/**
 * Register a device token for the user and return its value.
 */
function tripNotificationToken(User $user): string
{
    return UserDeviceToken::factory()->create(['user_id' => $user->id])->token;
}

function tripNotificationUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

/**
 * Every token sent in the only send() of the test, sorted to compare as a set.
 *
 * @return list<string>
 */
function tripNotificationSentTokens(): array
{
    $sent = tripNotificationPush()->sent;

    expect($sent)->toHaveCount(1);

    $tokens = $sent[0]['tokens'];
    sort($tokens);

    return $tokens;
}

/**
 * @param  list<string>  $tokens
 * @return list<string>
 */
function tripNotificationSorted(array $tokens): array
{
    sort($tokens);

    return $tokens;
}

/*
|--------------------------------------------------------------------------
| Binding
|--------------------------------------------------------------------------
*/

it('resuelve el contrato del dominio contra su implementación', function () {
    expect(tripNotificationService())->toBeInstanceOf(TripNotificationService::class);
});

/*
|--------------------------------------------------------------------------
| trip.assigned
|--------------------------------------------------------------------------
*/

it('envía trip.assigned solo a los tokens del piloto asignado', function () {
    $trip = Trip::factory()->assigned()->create();
    $pilot = User::findOrFail($trip->pilot_id);
    $first = tripNotificationToken($pilot);
    $second = tripNotificationToken($pilot);

    tripNotificationToken(User::findOrFail($trip->assigned_by));
    tripNotificationToken(tripNotificationUser(UserRole::Administrator));
    tripNotificationToken(tripNotificationUser(UserRole::Pilot));

    tripNotificationService()->notify($trip->id, TripNotificationType::Assigned);

    expect(tripNotificationSentTokens())->toBe(tripNotificationSorted([$first, $second]));
});

it('arma título, cuerpo y data de trip.assigned', function () {
    $trip = Trip::factory()->assigned()->create();
    tripNotificationToken(User::findOrFail($trip->pilot_id));

    tripNotificationService()->notify($trip->id, TripNotificationType::Assigned);

    $sent = tripNotificationPush()->sent[0];

    expect($sent['title'])->toBe('Nuevo viaje asignado')
        ->and($sent['body'])->toBe("Orden {$trip->order} · {$trip->location->name}")
        ->and($sent['data'])->toBe(['type' => 'trip.assigned', 'tripId' => (string) $trip->id]);
});

it('no envía trip.assigned a un viaje sin piloto', function () {
    $trip = Trip::factory()->create();
    tripNotificationToken(tripNotificationUser(UserRole::Administrator));

    tripNotificationService()->notify($trip->id, TripNotificationType::Assigned);

    expect(tripNotificationPush()->sent)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| trip.started / trip.finished
|--------------------------------------------------------------------------
*/

it('envía el inicio y el fin a los no-piloto, con solo el dueño de la empresa que tomó el viaje', function (TripNotificationType $type) {
    $trip = Trip::factory()->inRoute()->create();

    $expected = [tripNotificationToken(User::findOrFail($trip->assigned_by))];

    foreach ([UserRole::Administrator, UserRole::Manager, UserRole::Export, UserRole::User, UserRole::Shipment] as $role) {
        $expected[] = tripNotificationToken(tripNotificationUser($role));
    }

    /** Fuera: el piloto asignado, otro piloto, un carrier ajeno y un carrier sin empresa. */
    tripNotificationToken(User::findOrFail($trip->pilot_id));
    tripNotificationToken(tripNotificationUser(UserRole::Pilot));
    tripNotificationToken(Carrier::factory()->create()->owner);
    tripNotificationToken(tripNotificationUser(UserRole::Carrier));

    tripNotificationService()->notify($trip->id, $type);

    expect(tripNotificationSentTokens())->toBe(tripNotificationSorted($expected));
})->with([
    'trip.started' => [TripNotificationType::Started],
    'trip.finished' => [TripNotificationType::Finished],
]);

it('arma título, cuerpo y data del inicio y del fin', function (TripNotificationType $type, string $title, string $verb) {
    $trip = Trip::factory()->inRoute()->create();
    tripNotificationToken(tripNotificationUser(UserRole::Administrator));

    tripNotificationService()->notify($trip->id, $type);

    $sent = tripNotificationPush()->sent[0];

    expect($sent['title'])->toBe($title)
        ->and($sent['body'])->toBe("Orden {$trip->order} · {$trip->pilot->name} {$verb} {$trip->location->name}")
        ->and($sent['data'])->toBe(['type' => $type->value, 'tripId' => (string) $trip->id]);
})->with([
    'trip.started' => [TripNotificationType::Started, 'Viaje iniciado', 'en ruta a'],
    'trip.finished' => [TripNotificationType::Finished, 'Viaje finalizado', 'llegó a'],
]);

/*
|--------------------------------------------------------------------------
| Sin envío
|--------------------------------------------------------------------------
*/

it('no hace nada si el viaje no existe', function () {
    tripNotificationToken(tripNotificationUser(UserRole::Administrator));

    tripNotificationService()->notify(999999, TripNotificationType::Started);

    expect(tripNotificationPush()->sent)->toBe([]);
});

it('no hace nada si el viaje está borrado', function () {
    $trip = Trip::factory()->inRoute()->trashed()->create();
    tripNotificationToken(tripNotificationUser(UserRole::Administrator));

    tripNotificationService()->notify($trip->id, TripNotificationType::Started);

    expect(tripNotificationPush()->sent)->toBe([]);
});

it('no llama al contrato de envío si ningún destinatario tiene token', function () {
    $push = Mockery::mock(PushNotificationServiceInterface::class);
    $push->shouldNotReceive('send');
    app()->instance(PushNotificationServiceInterface::class, $push);

    $trip = Trip::factory()->inRoute()->create();

    tripNotificationService()->notify($trip->id, TripNotificationType::Started);
    tripNotificationService()->notify($trip->id, TripNotificationType::Assigned);
});

/*
|--------------------------------------------------------------------------
| Limpieza y degradado
|--------------------------------------------------------------------------
*/

it('borra los tokens rechazados y deja los demás', function () {
    $trip = Trip::factory()->inRoute()->create();
    $dead = tripNotificationToken(tripNotificationUser(UserRole::Administrator));
    $alive = tripNotificationToken(tripNotificationUser(UserRole::Manager));

    tripNotificationPush()->rejectedTokens = [$dead];

    tripNotificationService()->notify($trip->id, TripNotificationType::Started);

    expect(UserDeviceToken::query()->where('token', $dead)->exists())->toBeFalse()
        ->and(UserDeviceToken::query()->where('token', $alive)->exists())->toBeTrue();
});

it('registra el fallo del proveedor sin lanzar ni borrar tokens', function () {
    Log::spy();

    $trip = Trip::factory()->inRoute()->create();
    tripNotificationToken(tripNotificationUser(UserRole::Administrator));

    tripNotificationPush()->failing = true;
    tripNotificationPush()->rejectedTokens = UserDeviceToken::query()->pluck('token')->all();

    tripNotificationService()->notify($trip->id, TripNotificationType::Started);

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'No se pudo enviar la notificación del viaje'
            && $context['tripId'] === $trip->id
            && $context['type'] === 'trip.started',
    );

    expect(UserDeviceToken::query()->count())->toBe(1);
});
