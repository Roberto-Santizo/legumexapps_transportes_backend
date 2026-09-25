<?php

use App\Enums\TripNotificationType;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Interfaces\PushNotification\PushNotificationServiceInterface;
use App\Jobs\SendTripNotification;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripFuel;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Models\Vehicle;
use App\Providers\PushNotification\PushNotificationProvider;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\Doubles\InMemoryPushNotificationService;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Notificaciones push de viajes (SPEC 35)
|--------------------------------------------------------------------------
|
| Todo por HTTP contra el doble global de tests/Pest.php.
|
| El job sale con dispatchAfterResponse(), que cuelga un callback de terminating()
| del contenedor. En producción cada petición es un proceso nuevo, pero aquí el
| contenedor sobrevive entre las peticiones de un mismo test y terminate() no vacía
| la lista: la segunda petición volvería a correr el job de la primera. Por eso este
| archivo despacha en síncrono —mismo job, mismo service, después del commit— y un
| test aparte comprueba con Bus::fake() que las tres acciones lo difieren.
|
*/

beforeEach(function (): void {
    Bus::withoutDispatchingAfterResponses();
});

if (! function_exists('userWithRole')) {
    /**
     * Create a confirmed user with the given role.
     */
    function userWithRole(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }
}

if (! function_exists('asUser')) {
    /**
     * Authenticate the next request as the given user.
     */
    function asUser(User $user): TestCase
    {
        resetAuthState();

        return test()->withToken(JWTAuth::fromUser($user))->withHeader('Accept', 'application/json');
    }
}

/**
 * The global double, to read every send() that reached the provider.
 */
function tripNotificationDouble(): InMemoryPushNotificationService
{
    return app(PushNotificationServiceInterface::class);
}

/**
 * A carrier company able to take a trip: owner, linked pilot and active vehicle.
 *
 * @return array{carrier: Carrier, owner: User, pilot: User, vehicle: Vehicle}
 */
function tripNotificationTeam(): array
{
    $carrier = Carrier::factory()->create();
    $pilot = User::factory()->create(['role' => UserRole::Pilot]);
    $carrier->pilots()->attach($pilot);

    return [
        'carrier' => $carrier,
        'owner' => $carrier->owner,
        'pilot' => $pilot,
        'vehicle' => Vehicle::factory()->create(['carrier_id' => $carrier->id]),
    ];
}

/**
 * @param  array{carrier: Carrier, owner: User, pilot: User, vehicle: Vehicle}  $team
 * @return array<string, mixed>
 */
function tripNotificationAssignmentBody(array $team): array
{
    return [
        'pilotId' => $team['pilot']->id,
        'vehicleId' => $team['vehicle']->id,
        'fuelGallons' => 45.5,
        'fuelType' => 'diesel',
    ];
}

/**
 * A trip already taken by the team, with a confirmed load so `/start` accepts it.
 *
 * @param  array{carrier: Carrier, owner: User, pilot: User, vehicle: Vehicle}  $team
 * @param  array<string, mixed>  $attributes
 */
function tripNotificationTakenBy(array $team, array $attributes = []): Trip
{
    $trip = Trip::factory()->create([
        'pilot_id' => $team['pilot']->id,
        'vehicle_id' => $team['vehicle']->id,
        'assigned_by' => $team['owner']->id,
        ...$attributes,
    ]);

    TripFuel::factory()->confirmed()->create(['trip_id' => $trip->id]);

    return $trip;
}

/**
 * Register a device token for the user and return its value.
 */
function tripNotificationDeviceToken(User $user): string
{
    return UserDeviceToken::factory()->create(['user_id' => $user->id])->token;
}

/**
 * @param  list<string>  $tokens
 * @return list<string>
 */
function tripNotificationSortedTokens(array $tokens): array
{
    sort($tokens);

    return $tokens;
}

/**
 * Every token that reached the only send() of the test, sorted.
 *
 * @return list<string>
 */
function tripNotificationOnlySend(string $type): array
{
    $sent = tripNotificationDouble()->sent;

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['data']['type'])->toBe($type);

    return tripNotificationSortedTokens($sent[0]['tokens']);
}

/**
 * One token per non-pilot, non-carrier role, plus the owner of the team: every
 * expected recipient of `trip.started` and `trip.finished`.
 *
 * @param  array{carrier: Carrier, owner: User, pilot: User, vehicle: Vehicle}  $team
 * @return list<string>
 */
function tripNotificationFollowers(array $team): array
{
    $tokens = [tripNotificationDeviceToken($team['owner'])];

    foreach ([UserRole::Administrator, UserRole::Manager, UserRole::Export, UserRole::User, UserRole::Shipment] as $role) {
        $tokens[] = tripNotificationDeviceToken(userWithRole($role));
    }

    return $tokens;
}

/**
 * Tokens of everyone who must never hear about the start nor the finish.
 *
 * @param  array{carrier: Carrier, owner: User, pilot: User, vehicle: Vehicle}  $team
 */
function tripNotificationOutsiders(array $team): void
{
    tripNotificationDeviceToken($team['pilot']);
    tripNotificationDeviceToken(userWithRole(UserRole::Pilot));
    tripNotificationDeviceToken(Carrier::factory()->create()->owner);
    tripNotificationDeviceToken(userWithRole(UserRole::Carrier));
}

/*
|--------------------------------------------------------------------------
| Despacho diferido
|--------------------------------------------------------------------------
*/

it('las tres acciones despachan el job después de la respuesta', function () {
    Bus::fake();

    $team = tripNotificationTeam();
    $trip = Trip::factory()->create();

    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", tripNotificationAssignmentBody($team))
        ->assertOk();

    TripFuel::query()->where('trip_id', $trip->id)->update(['loaded_at' => now(), 'confirmed_by' => $team['pilot']->id]);

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/start")->assertOk();
    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/finish")->assertOk();

    foreach ([TripNotificationType::Assigned, TripNotificationType::Started, TripNotificationType::Finished] as $type) {
        Bus::assertDispatchedAfterResponse(
            SendTripNotification::class,
            fn (SendTripNotification $job): bool => $job->tripId === $trip->id && $job->type === $type,
        );
    }

    Bus::assertDispatchedAfterResponseTimes(SendTripNotification::class, 3);
});

/*
|--------------------------------------------------------------------------
| /assignment → trip.assigned
|--------------------------------------------------------------------------
*/

it('envía trip.assigned solo a los tokens del piloto asignado', function () {
    $team = tripNotificationTeam();
    $trip = Trip::factory()->create();

    $pilotTokens = [tripNotificationDeviceToken($team['pilot']), tripNotificationDeviceToken($team['pilot'])];
    tripNotificationDeviceToken($team['owner']);
    tripNotificationDeviceToken(userWithRole(UserRole::Administrator));
    tripNotificationDeviceToken(userWithRole(UserRole::Pilot));

    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", tripNotificationAssignmentBody($team))
        ->assertOk();

    expect(tripNotificationOnlySend('trip.assigned'))->toBe(tripNotificationSortedTokens($pilotTokens));

    $sent = tripNotificationDouble()->sent[0];
    $trip->refresh();

    expect($sent['title'])->toBe('Nuevo viaje asignado')
        ->and($sent['body'])->toBe('Tienes un nuevo viaje asignado con fecha de recolección: '.$trip->recolection_date->format('d-m-Y h:i:s A'))
        ->and($sent['data'])->toBe(['type' => 'trip.assigned', 'tripId' => (string) $trip->id]);
});

it('reasignar vuelve a notificar al piloto nuevo y no al anterior', function () {
    $team = tripNotificationTeam();
    $trip = Trip::factory()->create();

    $newPilot = User::factory()->create(['role' => UserRole::Pilot]);
    $team['carrier']->pilots()->attach($newPilot);

    $oldToken = tripNotificationDeviceToken($team['pilot']);
    $newToken = tripNotificationDeviceToken($newPilot);

    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", tripNotificationAssignmentBody($team))
        ->assertOk();
    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", [
        ...tripNotificationAssignmentBody($team),
        'pilotId' => $newPilot->id,
    ])->assertOk();

    $sent = tripNotificationDouble()->sent;

    expect($sent)->toHaveCount(2)
        ->and($sent[0]['tokens'])->toBe([$oldToken])
        ->and($sent[1]['tokens'])->toBe([$newToken]);
});

it('reasignar al mismo piloto vuelve a notificarlo', function () {
    $team = tripNotificationTeam();
    $trip = Trip::factory()->create();
    $token = tripNotificationDeviceToken($team['pilot']);

    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", tripNotificationAssignmentBody($team))
        ->assertOk();
    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", tripNotificationAssignmentBody($team))
        ->assertOk();

    expect(tripNotificationDouble()->sent)->toHaveCount(2)
        ->and(array_column(tripNotificationDouble()->sent, 'tokens'))->toBe([[$token], [$token]]);
});

/*
|--------------------------------------------------------------------------
| /start y /finish → trip.started / trip.finished
|--------------------------------------------------------------------------
*/

it('envía trip.started a los no-piloto dentro del ámbito', function () {
    $team = tripNotificationTeam();
    $trip = tripNotificationTakenBy($team);

    $expected = tripNotificationFollowers($team);
    tripNotificationOutsiders($team);

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/start")->assertOk();

    expect(tripNotificationOnlySend('trip.started'))->toBe(tripNotificationSortedTokens($expected));

    $sent = tripNotificationDouble()->sent[0];

    expect($sent['title'])->toBe('Viaje iniciado')
        ->and($sent['body'])->toBe("Orden {$trip->order} · {$team['pilot']->name} en ruta a {$trip->location->name}")
        ->and($sent['data'])->toBe(['type' => 'trip.started', 'tripId' => (string) $trip->id]);
});

it('envía trip.finished a los mismos destinatarios que trip.started', function () {
    $team = tripNotificationTeam();
    $trip = tripNotificationTakenBy($team, [
        'status' => TripStatus::InRoute,
        'start_date' => now()->subHours(2),
    ]);

    $expected = tripNotificationFollowers($team);
    tripNotificationOutsiders($team);

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/finish")->assertOk();

    expect(tripNotificationOnlySend('trip.finished'))->toBe(tripNotificationSortedTokens($expected));

    $sent = tripNotificationDouble()->sent[0];

    expect($sent['title'])->toBe('Viaje finalizado')
        ->and($sent['body'])->toBe("Orden {$trip->order} · {$team['pilot']->name} llegó a {$trip->location->name}")
        ->and($sent['data'])->toBe(['type' => 'trip.finished', 'tripId' => (string) $trip->id]);
});

/*
|--------------------------------------------------------------------------
| Sin envío
|--------------------------------------------------------------------------
*/

it('el PATCH general del administrador no notifica aunque cambie el status', function () {
    $team = tripNotificationTeam();
    $trip = tripNotificationTakenBy($team);
    tripNotificationFollowers($team);
    tripNotificationDeviceToken($team['pilot']);

    asUser(userWithRole(UserRole::Administrator))
        ->patchJson("/api/trips/{$trip->id}", ['status' => TripStatus::InRoute->value])
        ->assertOk();

    expect(tripNotificationDouble()->sent)->toBe([]);
});

it('una acción que responde 4xx no notifica', function (string $action, Closure $scene, int $status) {
    $team = tripNotificationTeam();
    tripNotificationFollowers($team);
    tripNotificationDeviceToken($team['pilot']);

    [$user, $uri, $body] = $scene($team);

    asUser($user)->patchJson($uri, $body)->assertStatus($status);

    expect(tripNotificationDouble()->sent)->toBe([]);
})->with([
    'assignment 404' => ['assignment', fn (array $team) => [$team['owner'], '/api/trips/999999/assignment', tripNotificationAssignmentBody($team)], 404],
    'assignment 422' => ['assignment', fn (array $team) => [$team['owner'], '/api/trips/'.Trip::factory()->create()->id.'/assignment', []], 422],
    'assignment 403' => ['assignment', fn (array $team) => [userWithRole(UserRole::Administrator), '/api/trips/'.Trip::factory()->create()->id.'/assignment', tripNotificationAssignmentBody($team)], 403],
    'assignment 400' => ['assignment', fn (array $team) => [$team['owner'], '/api/trips/'.tripNotificationTakenBy($team, ['status' => TripStatus::InRoute, 'start_date' => now()])->id.'/assignment', tripNotificationAssignmentBody($team)], 400],
    'start 403' => ['start', fn (array $team) => [userWithRole(UserRole::Pilot), '/api/trips/'.tripNotificationTakenBy($team)->id.'/start', []], 403],
    'start 400' => ['start', fn (array $team) => [$team['pilot'], '/api/trips/'.tripNotificationTakenBy($team, ['status' => TripStatus::InRoute, 'start_date' => now()])->id.'/start', []], 400],
    'finish 400' => ['finish', fn (array $team) => [$team['pilot'], '/api/trips/'.tripNotificationTakenBy($team)->id.'/finish', []], 400],
    'finish 404' => ['finish', fn (array $team) => [$team['pilot'], '/api/trips/999999/finish', []], 404],
]);

/*
|--------------------------------------------------------------------------
| Limpieza y degradado
|--------------------------------------------------------------------------
*/

it('borra los tokens que rechaza el proveedor y deja los demás', function () {
    $team = tripNotificationTeam();
    $trip = tripNotificationTakenBy($team);

    $dead = tripNotificationDeviceToken(userWithRole(UserRole::Administrator));
    $alive = tripNotificationDeviceToken(userWithRole(UserRole::Manager));
    tripNotificationDouble()->rejectedTokens = [$dead];

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/start")->assertOk();

    expect(UserDeviceToken::query()->where('token', $dead)->exists())->toBeFalse()
        ->and(UserDeviceToken::query()->where('token', $alive)->exists())->toBeTrue();
});

it('si el envío falla la acción responde igual, se registra el error y no se borra ningún token', function (string $action) {
    Log::spy();

    $team = tripNotificationTeam();
    $trip = match ($action) {
        'assignment' => Trip::factory()->create(),
        'start' => tripNotificationTakenBy($team),
        'finish' => tripNotificationTakenBy($team, ['status' => TripStatus::InRoute, 'start_date' => now()->subHour()]),
    };

    tripNotificationFollowers($team);
    tripNotificationDeviceToken($team['pilot']);
    $tokenCount = UserDeviceToken::query()->count();

    tripNotificationDouble()->failing = true;
    tripNotificationDouble()->rejectedTokens = UserDeviceToken::query()->pluck('token')->all();

    $user = $action === 'assignment' ? $team['owner'] : $team['pilot'];
    $body = $action === 'assignment' ? tripNotificationAssignmentBody($team) : [];

    asUser($user)->patchJson("/api/trips/{$trip->id}/{$action}", $body)
        ->assertOk()
        ->assertJsonPath('statusCode', 200);

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message): bool => $message === 'No se pudo enviar la notificación del viaje',
    );

    expect(UserDeviceToken::query()->count())->toBe($tokenCount);
})->with(['assignment', 'start', 'finish']);

it('sin credencial de Firebase las tres acciones responden igual', function () {
    Log::spy();

    /** El binding real, no el doble: sin FIREBASE_CREDENTIALS kreait falla al resolverse. */
    app()->forgetInstance(PushNotificationServiceInterface::class);
    new PushNotificationProvider(app())->register();
    config(['firebase.projects.app.credentials' => null]);

    $team = tripNotificationTeam();
    $trip = Trip::factory()->create();
    tripNotificationFollowers($team);
    tripNotificationDeviceToken($team['pilot']);

    asUser($team['owner'])->patchJson("/api/trips/{$trip->id}/assignment", tripNotificationAssignmentBody($team))
        ->assertOk();

    TripFuel::query()->where('trip_id', $trip->id)->update(['loaded_at' => now(), 'confirmed_by' => $team['pilot']->id]);

    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/start")->assertOk();
    asUser($team['pilot'])->patchJson("/api/trips/{$trip->id}/finish")->assertOk();

    Log::shouldHaveReceived('error')->times(3);
});
