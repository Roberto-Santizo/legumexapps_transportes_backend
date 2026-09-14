<?php

use App\Enums\UserRole;
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
 * The four routes of the domain, all of them `GET` and all of them protected the same way.
 *
 * @return array<string, array{0: string}>
 */
function dashboardEndpoints(): array
{
    return [
        'trips' => ['/api/dashboard/trips'],
        'trips in route' => ['/api/dashboard/trips/in-route'],
        'vehicle expenses' => ['/api/dashboard/vehicle-expenses'],
        'vehicles' => ['/api/dashboard/vehicles'],
    ];
}

/**
 * The two roles `role:administrator,manager` keeps out of the whole domain.
 *
 * @return array<string, UserRole>
 */
function dashboardForbiddenRoles(): array
{
    return [
        'carrier' => UserRole::Carrier,
        'pilot' => UserRole::Pilot,
    ];
}

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

it('rechaza las rutas del tablero sin token', function (string $uri) {
    $this->getJson($uri)
        ->assertStatus(401)
        ->assertJsonPath('message', 'El token de sesión no es válido o ha expirado');
})->with(dashboardEndpoints());

it('rechaza con 403 a transportistas y pilotos en todas las rutas del tablero', function (string $uri, UserRole $role) {
    asUser(userWithRole($role))->getJson($uri)
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(dashboardEndpoints())->with(dashboardForbiddenRoles());
