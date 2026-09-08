<?php

use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * The callback `routes/channels.php` registered for `trips.{tripId}`.
 *
 * Se invoca el callback registrado, no la ruta HTTP: `phpunit.xml` fija
 * `BROADCAST_CONNECTION=null` y el `NullBroadcaster` tiene `auth()` vacío, así que
 * `POST /api/broadcasting/auth` respondería 200 tanto para quien alcanza el viaje
 * como para quien no — no distinguiría nada. Cambiar la conexión a una real solo
 * para el test metería la firma HMAC de Pusher en medio.
 *
 * `Broadcast::channel()` delega en el broadcaster, que guarda los callbacks en su
 * propiedad `channels`; de ahí se saca el que registró el arranque de la aplicación,
 * tal cual, sin volver a declararlo en el test.
 */
function tripChannelCallback(): Closure
{
    $broadcaster = Broadcast::driver();

    /** @var array<string, Closure> $channels */
    $channels = (new ReflectionProperty($broadcaster, 'channels'))->getValue($broadcaster);

    expect($channels)->toHaveKey('trips.{tripId}');

    return $channels['trips.{tripId}'];
}

/**
 * Ask the channel whether the given user may listen to the given trip.
 */
function tripChannelAllows(User $user, int $tripId): bool
{
    return tripChannelCallback()($user, $tripId);
}

function tripChannelUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

/*
|--------------------------------------------------------------------------
| El piloto emite y nada más
|--------------------------------------------------------------------------
*/

it('deja fuera del canal a cualquier piloto, incluido el asignado al viaje', function (bool $asignado) {
    $trip = Trip::factory()->inRoute()->create();

    $user = $asignado
        ? User::findOrFail($trip->pilot_id)
        : tripChannelUser(UserRole::Pilot);

    expect(tripChannelAllows($user, $trip->id))->toBeFalse();
})->with([
    'el piloto asignado' => true,
    'un piloto ajeno' => false,
]);

it('deja fuera del canal al piloto incluso sobre un viaje que no existe', function () {
    expect(tripChannelAllows(tripChannelUser(UserRole::Pilot), 99999))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Administrador y gerente alcanzan cualquier viaje
|--------------------------------------------------------------------------
*/

it('admite en el canal al administrador y al gerente sobre cualquier viaje', function (UserRole $role, string $estado) {
    $trip = $estado === 'pending'
        ? Trip::factory()->create()
        : Trip::factory()->{$estado}()->create();

    expect(tripChannelAllows(tripChannelUser($role), $trip->id))->toBeTrue();
})->with([
    'administrador' => UserRole::Administrator,
    'gerente' => UserRole::Manager,
])->with([
    'pendiente' => 'pending',
    'asignado' => 'assigned',
    'en ruta' => 'inRoute',
    'finalizado' => 'finished',
]);

/*
|--------------------------------------------------------------------------
| El transportista, dentro de su ámbito
|--------------------------------------------------------------------------
*/

it('admite en el canal al transportista sobre un viaje que asignó su empresa', function () {
    $trip = Trip::factory()->inRoute()->create();

    expect(tripChannelAllows(User::findOrFail($trip->assigned_by), $trip->id))->toBeTrue();
});

it('admite en el canal al transportista sobre un viaje pendiente sin tripulación', function () {
    $trip = Trip::factory()->create();

    expect(tripChannelAllows(Carrier::factory()->create()->owner, $trip->id))->toBeTrue();
});

it('deja fuera del canal al transportista sobre un viaje que asignó otra empresa', function () {
    $trip = Trip::factory()->inRoute()->create();

    expect(tripChannelAllows(Carrier::factory()->create()->owner, $trip->id))->toBeFalse();
});

it('deja fuera del canal al transportista sin empresa vinculada', function () {
    $trip = Trip::factory()->inRoute()->create();

    expect(tripChannelAllows(tripChannelUser(UserRole::Carrier), $trip->id))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Viajes que no se alcanzan por HTTP tampoco se escuchan
|--------------------------------------------------------------------------
*/

it('deja fuera del canal a quien pide un viaje inexistente o borrado', function (bool $borrado) {
    $tripId = $borrado
        ? Trip::factory()->inRoute()->trashed()->create()->id
        : 99999;

    expect(tripChannelAllows(tripChannelUser(UserRole::Administrator), $tripId))->toBeFalse();
})->with([
    'inexistente' => false,
    'borrado' => true,
]);

/*
|--------------------------------------------------------------------------
| La ruta de autorización, por HTTP
|--------------------------------------------------------------------------
*/

it('protege la ruta de autorización de canales con jwt.auth', function () {
    $this->postJson('/api/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => 'private-trips.1',
    ])
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
});
