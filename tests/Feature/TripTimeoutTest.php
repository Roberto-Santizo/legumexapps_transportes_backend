<?php

use App\Enums\UserRole;
use App\Models\Trip;
use App\Models\TripTimeout;
use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

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
     *
     * The JWT singletons survive between calls of the same test, so the guard
     * state is dropped before handing the fresh token over.
     */
    function asUser(User $user): TestCase
    {
        resetAuthState();

        return test()->withToken(JWTAuth::fromUser($user))->withHeader('Accept', 'application/json');
    }
}

/**
 * A trip already in route, with its assigned pilot and the owner of the company that
 * took it.
 *
 * @return array{trip: Trip, pilot: User, owner: User}
 */
function tripWithTimeouts(): array
{
    $trip = Trip::factory()->inRoute()->create();

    return [
        'trip' => $trip,
        'pilot' => User::findOrFail($trip->pilot_id),
        'owner' => User::findOrFail($trip->assigned_by),
    ];
}

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

it('rechaza la consulta de paradas sin token', function () {
    $this->getJson('/api/trips/1/timeouts')
        ->assertStatus(401)
        ->assertJsonPath('message', 'El token de sesión no es válido o ha expirado');
});

it('rechaza a cualquier piloto, incluido el asignado al viaje', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripWithTimeouts();

    TripTimeout::factory()->create(['trip_id' => $trip->id]);

    asUser($pilot)->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertStatus(403)
        ->assertJsonPath('message', 'No tienes permisos para consultar las paradas de un viaje');
});

/*
|--------------------------------------------------------------------------
| Ámbito
|--------------------------------------------------------------------------
*/

it('deja al administrador y al gerente leer las paradas de cualquier viaje', function (UserRole $role) {
    ['trip' => $trip] = tripWithTimeouts();

    TripTimeout::factory()->create(['trip_id' => $trip->id]);

    asUser(userWithRole($role))->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertStatus(200)
        ->assertJsonPath('message', 'Paradas obtenidas correctamente')
        ->assertJsonCount(1, 'data');
})->with([
    'administrator' => UserRole::Administrator,
    'manager' => UserRole::Manager,
]);

it('deja al transportista leer las paradas de un viaje que asignó su empresa', function () {
    ['trip' => $trip, 'owner' => $owner] = tripWithTimeouts();

    TripTimeout::factory()->create(['trip_id' => $trip->id]);

    asUser($owner)->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertStatus(200)
        ->assertJsonCount(1, 'data');
});

/*
|--------------------------------------------------------------------------
| Lectura
|--------------------------------------------------------------------------
*/

it('devuelve las paradas ordenadas por su hora de inicio ascendente', function () {
    ['trip' => $trip] = tripWithTimeouts();

    $second = TripTimeout::factory()->create(['trip_id' => $trip->id, 'started_at' => now()->subHour()]);
    $first = TripTimeout::factory()->create(['trip_id' => $trip->id, 'started_at' => now()->subHours(4)]);

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertStatus(200)
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.1.id', $second->id);
});

it('devuelve 200 con data vacío para un viaje sin paradas', function () {
    ['trip' => $trip] = tripWithTimeouts();

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');
});

it('devuelve las nueve claves del recurso', function () {
    ['trip' => $trip] = tripWithTimeouts();

    TripTimeout::factory()->create(['trip_id' => $trip->id]);

    $response = asUser(userWithRole(UserRole::Administrator))
        ->getJson("/api/trips/{$trip->id}/timeouts")
        ->assertStatus(200);

    expect(array_keys($response->json('data.0')))->toBe([
        'id',
        'latitude',
        'longitude',
        'startedAt',
        'endedAt',
        'durationMinutes',
        'pilotId',
        'startPositionId',
        'endPositionId',
    ]);
});

it('acota el tamaño de página a diez y aplana los metadatos en la raíz', function () {
    ['trip' => $trip] = tripWithTimeouts();

    TripTimeout::factory()->count(12)->create(['trip_id' => $trip->id]);

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$trip->id}/timeouts?limit=3")
        ->assertStatus(200)
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('total', 12)
        ->assertJsonPath('currentPage', 1);
});
