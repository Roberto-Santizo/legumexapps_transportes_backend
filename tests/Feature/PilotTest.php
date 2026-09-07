<?php

use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\CarrierPilot;
use App\Models\CarrierPilotSalaryHistory;
use App\Models\PilotDocument;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * The three — and only three — pilots endpoints, as method and URI.
 *
 * The domain has no store, show, update nor destroy: those routes do not exist
 * and must not exist.
 *
 * @return array<string, array{string, string}>
 */
function pilotEndpoints(int|string $pilot = 1): array
{
    return [
        'index' => ['GET', '/api/pilots'],
        'salary' => ['PATCH', "/api/pilots/{$pilot}/salary"],
        'salary-history' => ['GET', "/api/pilots/{$pilot}/salary-history"],
    ];
}

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
 * Link a brand new pilot to the given company, optionally with a salary already set.
 *
 * CarrierPilot has no factory: the pivot row is created by hand, as CarrierServiceTest
 * already does.
 */
function pilotLinkedTo(Carrier $carrier, int|float|string|null $salary = null): CarrierPilot
{
    return CarrierPilot::create([
        'carrier_id' => $carrier->id,
        'user_id' => User::factory()->create(['role' => UserRole::Pilot])->id,
        'salary' => $salary,
    ]);
}

/**
 * The ids returned inside the data key of a listing response.
 *
 * @return array<int, int>
 */
function listedPilotIds(array $data): array
{
    return collect($data)->pluck('id')->all();
}

/*
|--------------------------------------------------------------------------
| Vínculo recién creado
|--------------------------------------------------------------------------
*/

/** joinCarrier() de SPEC 03 no cambió: la columna nueva se queda en null por omisión. */
it('deja en null el salario del piloto que acaba de unirse con el código', function () {
    $carrier = Carrier::factory()->create(['code' => 'A7K2QX']);
    $pilot = userWithRole(UserRole::Pilot);

    asUser($pilot)->postJson('/api/carriers/join', ['code' => 'A7K2QX'])->assertOk();

    $this->assertDatabaseHas('carrier_pilots', [
        'carrier_id' => $carrier->id,
        'user_id' => $pilot->id,
        'salary' => null,
    ]);

    asUser($carrier->owner)->getJson('/api/pilots')
        ->assertOk()
        ->assertJsonPath('data.0.id', $pilot->id)
        ->assertJsonPath('data.0.salary', null);
});

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth, role y carrier.required
|--------------------------------------------------------------------------
*/

it('rechaza con 401 cualquier endpoint de pilotos sin token', function (string $method, string $uri) {
    $this->json($method, $uri, ['salary' => 4500])
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(pilotEndpoints());

it('rechaza con 403 a un piloto en cualquier endpoint de pilotos', function (string $method, string $uri) {
    asUser(userWithRole(UserRole::Pilot))->json($method, $uri, ['salary' => 4500])
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
})->with(pilotEndpoints());

it('rechaza con 403 a un piloto que usa su propio user_id', function (string $endpoint) {
    $pilot = pilotLinkedTo(Carrier::factory()->create(), 4500);

    [$method, $uri] = pilotEndpoints($pilot->user_id)[$endpoint];

    asUser($pilot->user)->json($method, $uri, ['salary' => 5200])
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);

    $this->assertDatabaseHas('carrier_pilots', ['id' => $pilot->id, 'salary' => 4500]);
})->with(['index', 'salary', 'salary-history']);

it('bloquea con carrier.required a un carrier sin empresa', function (string $method, string $uri) {
    asUser(userWithRole(UserRole::Carrier))->json($method, $uri, ['salary' => 4500])
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'Debes estar vinculado a un transportista para acceder a este recurso',
            'data' => null,
        ]);
})->with(pilotEndpoints());

it('deja pasar los tres endpoints a un administrador sin empresa', function () {
    $pilot = pilotLinkedTo(Carrier::factory()->create());
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->getJson('/api/pilots')->assertOk()->assertJsonCount(1, 'data');

    asUser($admin)->patchJson("/api/pilots/{$pilot->user_id}/salary", ['salary' => 4500])->assertOk();

    asUser($admin)->getJson("/api/pilots/{$pilot->user_id}/salary-history")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('rechaza con 403 a un manager que intenta cambiar un salario', function () {
    $pilot = pilotLinkedTo(Carrier::factory()->create(), 4500);

    asUser(userWithRole(UserRole::Manager))->patchJson("/api/pilots/{$pilot->user_id}/salary", ['salary' => 5200])
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);

    $this->assertDatabaseHas('carrier_pilots', ['id' => $pilot->id, 'salary' => 4500]);
    $this->assertDatabaseCount('carrier_pilot_salary_histories', 0);
});

it('deja a un manager leer el listado y el historial de cualquier piloto', function () {
    $pilot = pilotLinkedTo(Carrier::factory()->create());
    $manager = userWithRole(UserRole::Manager);

    CarrierPilotSalaryHistory::factory()->create(['carrier_pilot_id' => $pilot->id]);

    asUser($manager)->getJson('/api/pilots')->assertOk()->assertJsonCount(1, 'data');

    asUser($manager)->getJson("/api/pilots/{$pilot->user_id}/salary-history")
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

/*
|--------------------------------------------------------------------------
| GET /api/pilots
|--------------------------------------------------------------------------
*/

it('devuelve a un carrier solo los pilotos de su empresa', function () {
    $carrier = Carrier::factory()->create();
    $otra = Carrier::factory()->create();

    $propios = collect([pilotLinkedTo($carrier), pilotLinkedTo($carrier)]);
    $ajeno = pilotLinkedTo($otra);

    $response = asUser($carrier->owner)->getJson('/api/pilots');

    $response->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Pilotos obtenidos correctamente',
        ])
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => [['id', 'name', 'email', 'carrierId', 'carrierName', 'salary', 'joinedAt']],
        ]);

    expect(listedPilotIds($response->json('data')))
        ->toEqualCanonicalizing($propios->pluck('user_id')->all())
        ->not->toContain($ajeno->user_id);
});

it('ignora el carrierId que manda un carrier y le devuelve los suyos', function () {
    $carrier = Carrier::factory()->create();
    $otra = Carrier::factory()->create();

    $propio = pilotLinkedTo($carrier);
    pilotLinkedTo($otra);
    pilotLinkedTo($otra);

    $response = asUser($carrier->owner)->getJson("/api/pilots?carrierId={$otra->id}");

    $response->assertOk()->assertJsonCount(1, 'data');

    expect(listedPilotIds($response->json('data')))->toBe([$propio->user_id]);
});

it('devuelve los pilotos de todas las empresas a los roles sin ámbito', function (UserRole $role) {
    $pilots = collect([
        pilotLinkedTo(Carrier::factory()->create()),
        pilotLinkedTo(Carrier::factory()->create()),
        pilotLinkedTo(Carrier::factory()->create()),
    ]);

    $response = asUser(userWithRole($role))->getJson('/api/pilots');

    $response->assertOk()->assertJsonCount(3, 'data');

    expect(listedPilotIds($response->json('data')))->toEqualCanonicalizing($pilots->pluck('user_id')->all());
})->with([
    'administrador' => UserRole::Administrator,
    'manager' => UserRole::Manager,
]);

it('acota el listado al carrierId pedido por un rol sin ámbito', function (UserRole $role) {
    $unaEmpresa = Carrier::factory()->create();
    $otraEmpresa = Carrier::factory()->create();

    $suyos = collect([pilotLinkedTo($unaEmpresa), pilotLinkedTo($unaEmpresa)]);
    pilotLinkedTo($otraEmpresa);

    $response = asUser(userWithRole($role))->getJson("/api/pilots?carrierId={$unaEmpresa->id}");

    $response->assertOk()->assertJsonCount(2, 'data');

    expect(listedPilotIds($response->json('data')))->toEqualCanonicalizing($suyos->pluck('user_id')->all());
})->with([
    'administrador' => UserRole::Administrator,
    'manager' => UserRole::Manager,
]);

it('devuelve una lista vacía con un carrierId de una empresa inexistente', function () {
    pilotLinkedTo(Carrier::factory()->create());

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/pilots?carrierId=99999')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('data', []);
});

it('ignora sin error un carrierId que no es numérico', function () {
    pilotLinkedTo(Carrier::factory()->create());
    pilotLinkedTo(Carrier::factory()->create());

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/pilots?carrierId=abc')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('devuelve el user_id como id del piloto y nunca el id de la pivote', function () {
    $carrier = Carrier::factory()->create(['name' => 'Transportes del Norte']);

    /** Dos pilotos previos en otra empresa, para que el id de la pivote no coincida con el user_id. */
    pilotLinkedTo(Carrier::factory()->create());
    pilotLinkedTo(Carrier::factory()->create());

    $pilot = pilotLinkedTo($carrier, 4500);

    $response = asUser($carrier->owner)->getJson('/api/pilots');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $pilot->user_id)
        ->assertJsonPath('data.0.name', $pilot->user->name)
        ->assertJsonPath('data.0.email', $pilot->user->email)
        ->assertJsonPath('data.0.carrierId', $carrier->id)
        ->assertJsonPath('data.0.carrierName', 'Transportes del Norte')
        ->assertJsonPath('data.0.salary', '4500.00');

    expect($pilot->id)->not->toBe($pilot->user_id)
        ->and($response->json('data.0'))->not->toContain($pilot->id)
        ->and($response->json('data.0.joinedAt'))->toMatch('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} (AM|PM)$/');
});

it('devuelve salary en null cuando todavía no se ha asignado', function () {
    $carrier = Carrier::factory()->create();
    pilotLinkedTo($carrier);

    asUser($carrier->owner)->getJson('/api/pilots')
        ->assertOk()
        ->assertJsonPath('data.0.salary', null);
});

it('no dispara N+1 al listar pilotos de muchas empresas', function () {
    for ($i = 0; $i < 20; $i++) {
        pilotLinkedTo(Carrier::factory()->create(), 4500);
    }

    /** El token se emite antes de escuchar: sus claims consultan la empresa del usuario. */
    $token = JWTAuth::fromUser(userWithRole(UserRole::Administrator));

    resetAuthState();

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $this->withToken($token)->getJson('/api/pilots')->assertOk()->assertJsonCount(20, 'data');

    $sobrePilotos = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "carrier_pilots"'));
    $sobreEmpresas = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "carriers"'));
    $sobreUsuarios = collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "users"'));

    expect($sobrePilotos)->toHaveCount(1)
        ->and($sobreEmpresas)->toHaveCount(1)
        /** Coste fijo: dos resoluciones del usuario autenticado y un eager load para los 20 pilotos. */
        ->and($sobreUsuarios)->toHaveCount(3);
});

/*
|--------------------------------------------------------------------------
| Paginación del listado
|--------------------------------------------------------------------------
*/

it('devuelve todos los pilotos y ninguna clave de paginación sin limit', function () {
    $carrier = Carrier::factory()->create();

    for ($i = 0; $i < 12; $i++) {
        pilotLinkedTo($carrier);
    }

    $response = asUser($carrier->owner)->getJson('/api/pilots');

    $response->assertOk()->assertJsonCount(12, 'data');

    expect($response->json())->not->toHaveKeys(['total', 'currentPage', 'lastPage']);
});

it('devuelve el sobre paginado de pilotos con limit numérico', function () {
    $carrier = Carrier::factory()->create();

    for ($i = 0; $i < 12; $i++) {
        pilotLinkedTo($carrier);
    }

    asUser($carrier->owner)->getJson('/api/pilots?limit=10')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJson([
            'statusCode' => 200,
            'total' => 12,
            'currentPage' => 1,
            'lastPage' => 2,
        ])
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => [['id', 'name', 'email', 'carrierId', 'carrierName', 'salary', 'joinedAt']],
            'total',
            'currentPage',
            'lastPage',
        ]);
});

it('acota el limit del listado de pilotos a la horquilla [10, 100]', function (string $limit, int $porPagina, int $creados) {
    $carrier = Carrier::factory()->create();

    for ($i = 0; $i < $creados; $i++) {
        pilotLinkedTo($carrier);
    }

    asUser($carrier->owner)->getJson("/api/pilots?limit={$limit}")
        ->assertOk()
        ->assertJsonCount($porPagina, 'data')
        ->assertJsonPath('total', $creados)
        ->assertJsonPath('lastPage', 2);
})->with([
    'limit=1 pagina de 10 en 10' => ['1', 10, 12],
    'limit=500 pagina de 100 en 100' => ['500', 100, 101],
]);

it('devuelve todos los pilotos sin error cuando limit no es numérico', function () {
    $carrier = Carrier::factory()->create();

    for ($i = 0; $i < 12; $i++) {
        pilotLinkedTo($carrier);
    }

    $response = asUser($carrier->owner)->getJson('/api/pilots?limit=abc');

    $response->assertOk()->assertJsonCount(12, 'data');

    expect($response->json())->not->toHaveKey('total');
});

/*
|--------------------------------------------------------------------------
| PATCH /api/pilots/{pilot}/salary
|--------------------------------------------------------------------------
*/

it('asigna el primer salario y deja una sola fila de bitácora sin salario anterior', function () {
    $carrier = Carrier::factory()->create();
    $pilot = pilotLinkedTo($carrier);

    asUser($carrier->owner)->patchJson("/api/pilots/{$pilot->user_id}/salary", ['salary' => 4500])
        ->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Salario actualizado correctamente',
            'data' => [
                'id' => $pilot->user_id,
                'carrierId' => $carrier->id,
                'salary' => '4500.00',
            ],
        ]);

    $this->assertDatabaseHas('carrier_pilots', ['id' => $pilot->id, 'salary' => 4500]);

    $this->assertDatabaseCount('carrier_pilot_salary_histories', 1);
    $this->assertDatabaseHas('carrier_pilot_salary_histories', [
        'carrier_pilot_id' => $pilot->id,
        'previous_salary' => null,
        'new_salary' => 4500,
        'changed_by' => $carrier->owner->id,
    ]);
});

it('encadena la segunda asignación con el salario anterior', function () {
    $carrier = Carrier::factory()->create();
    $pilot = pilotLinkedTo($carrier, 4500);

    asUser($carrier->owner)->patchJson("/api/pilots/{$pilot->user_id}/salary", ['salary' => 5200])
        ->assertOk()
        ->assertJsonPath('data.salary', '5200.00');

    $this->assertDatabaseHas('carrier_pilot_salary_histories', [
        'carrier_pilot_id' => $pilot->id,
        'previous_salary' => 4500,
        'new_salary' => 5200,
    ]);
});

it('permite bajar el salario y lo registra igual que una subida', function () {
    $carrier = Carrier::factory()->create();
    $pilot = pilotLinkedTo($carrier, 4500);

    asUser($carrier->owner)->patchJson("/api/pilots/{$pilot->user_id}/salary", ['salary' => 3800])
        ->assertOk()
        ->assertJsonPath('data.salary', '3800.00');

    $this->assertDatabaseHas('carrier_pilots', ['id' => $pilot->id, 'salary' => 3800]);
    $this->assertDatabaseHas('carrier_pilot_salary_histories', [
        'carrier_pilot_id' => $pilot->id,
        'previous_salary' => 4500,
        'new_salary' => 3800,
    ]);
});

it('rechaza con 400 el mismo salario que el piloto ya tiene y no toca la bitácora', function (int|float|string $salary) {
    $carrier = Carrier::factory()->create();
    $pilot = pilotLinkedTo($carrier, 4500);

    CarrierPilotSalaryHistory::factory()->create([
        'carrier_pilot_id' => $pilot->id,
        'previous_salary' => null,
        'new_salary' => 4500,
    ]);

    asUser($carrier->owner)->patchJson("/api/pilots/{$pilot->user_id}/salary", ['salary' => $salary])
        ->assertBadRequest()
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El salario indicado es el mismo que el piloto ya tiene registrado',
            'data' => null,
        ]);

    $this->assertDatabaseCount('carrier_pilot_salary_histories', 1);
    $this->assertDatabaseHas('carrier_pilots', ['id' => $pilot->id, 'salary' => 4500]);
})->with([4500, 4500.00, '4500.00', 4500.004]);

it('registra como autor al usuario autenticado aunque el cuerpo mande otro', function () {
    $carrier = Carrier::factory()->create();
    $pilot = pilotLinkedTo($carrier);
    $otro = userWithRole(UserRole::Administrator);

    asUser($carrier->owner)->patchJson("/api/pilots/{$pilot->user_id}/salary", [
        'salary' => 4500,
        'changedBy' => $otro->id,
        'changed_by' => $otro->id,
    ])->assertOk();

    $this->assertDatabaseHas('carrier_pilot_salary_histories', [
        'carrier_pilot_id' => $pilot->id,
        'changed_by' => $carrier->owner->id,
    ]);
});

it('rechaza con 422 un salario inválido y no escribe nada', function (array $payload) {
    $carrier = Carrier::factory()->create();
    $pilot = pilotLinkedTo($carrier);

    asUser($carrier->owner)->patchJson("/api/pilots/{$pilot->user_id}/salary", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['salary']);

    $this->assertDatabaseHas('carrier_pilots', ['id' => $pilot->id, 'salary' => null]);
    $this->assertDatabaseCount('carrier_pilot_salary_histories', 0);
})->with([
    'cuerpo vacío' => [[]],
    'salario nulo' => [['salary' => null]],
    'salario cero' => [['salary' => 0]],
    'salario negativo' => [['salary' => -100]],
    'salario no numérico' => [['salary' => 'abc']],
    'salario fuera del máximo' => [['salary' => 100000000]],
]);

it('permite a un administrador cambiar el salario de un piloto de cualquier empresa', function () {
    $pilot = pilotLinkedTo(Carrier::factory()->create(), 4500);
    $admin = userWithRole(UserRole::Administrator);

    asUser($admin)->patchJson("/api/pilots/{$pilot->user_id}/salary", ['salary' => 5200])
        ->assertOk()
        ->assertJsonPath('data.salary', '5200.00');

    $this->assertDatabaseHas('carrier_pilot_salary_histories', [
        'carrier_pilot_id' => $pilot->id,
        'changed_by' => $admin->id,
    ]);
});

it('rechaza con 403 a un carrier que cambia el salario de un piloto de otra empresa', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = pilotLinkedTo(Carrier::factory()->create(), 4500);

    asUser($carrier->owner)->patchJson("/api/pilots/{$ajeno->user_id}/salary", ['salary' => 5200])
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No puedes acceder a un piloto que no pertenece a tu empresa transportista',
            'data' => null,
        ]);

    $this->assertDatabaseHas('carrier_pilots', ['id' => $ajeno->id, 'salary' => 4500]);
    $this->assertDatabaseCount('carrier_pilot_salary_histories', 0);
});

it('devuelve 404 al cambiar el salario de un user_id que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))->patchJson('/api/pilots/99999/salary', ['salary' => 4500])
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El piloto no existe o no está vinculado a ninguna empresa transportista',
            'data' => null,
        ]);
});

it('devuelve el mismo 404 con un usuario que existe pero no es piloto de nadie', function () {
    $suelto = userWithRole(UserRole::Pilot);

    asUser(userWithRole(UserRole::Administrator))->patchJson("/api/pilots/{$suelto->id}/salary", ['salary' => 4500])
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El piloto no existe o no está vinculado a ninguna empresa transportista',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| GET /api/pilots/{pilot}/salary-history
|--------------------------------------------------------------------------
*/

it('devuelve los cambios del más reciente al más antiguo', function () {
    $carrier = Carrier::factory()->create();
    $pilot = pilotLinkedTo($carrier);

    foreach ([4500, 5200, 4800] as $salary) {
        asUser($carrier->owner)->patchJson("/api/pilots/{$pilot->user_id}/salary", ['salary' => $salary])->assertOk();
    }

    $response = asUser($carrier->owner)->getJson("/api/pilots/{$pilot->user_id}/salary-history");

    $response->assertOk()
        ->assertJson([
            'statusCode' => 200,
            'message' => 'Historial de salario obtenido correctamente',
        ])
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.newSalary', '4800.00')
        ->assertJsonPath('data.0.previousSalary', '5200.00')
        ->assertJsonPath('data.1.newSalary', '5200.00')
        ->assertJsonPath('data.2.newSalary', '4500.00')
        /** La más antigua es la única con previousSalary null. */
        ->assertJsonPath('data.2.previousSalary', null);
});

it('devuelve las claves exactas de cada fila de la bitácora', function () {
    $carrier = Carrier::factory()->create();
    $pilot = pilotLinkedTo($carrier);

    asUser($carrier->owner)->patchJson("/api/pilots/{$pilot->user_id}/salary", ['salary' => 4500])->assertOk();

    $response = asUser($carrier->owner)->getJson("/api/pilots/{$pilot->user_id}/salary-history");

    $response->assertOk()
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => [['id', 'previousSalary', 'newSalary', 'changedById', 'changedByName', 'changedAt']],
        ])
        ->assertJsonPath('data.0.changedById', $carrier->owner->id)
        ->assertJsonPath('data.0.changedByName', $carrier->owner->name);

    expect($response->json('data.0.changedAt'))->toMatch('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} (AM|PM)$/');
});

it('devuelve una lista vacía con 200 para un piloto sin ningún cambio', function () {
    $carrier = Carrier::factory()->create();
    $pilot = pilotLinkedTo($carrier);

    asUser($carrier->owner)->getJson("/api/pilots/{$pilot->user_id}/salary-history")
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('data', []);
});

it('devuelve el historial completo y ninguna clave de paginación sin limit', function () {
    $carrier = Carrier::factory()->create();
    $pilot = pilotLinkedTo($carrier);

    CarrierPilotSalaryHistory::factory()->count(12)->create(['carrier_pilot_id' => $pilot->id]);

    $response = asUser($carrier->owner)->getJson("/api/pilots/{$pilot->user_id}/salary-history");

    $response->assertOk()->assertJsonCount(12, 'data');

    expect($response->json())->not->toHaveKeys(['total', 'currentPage', 'lastPage']);
});

it('pagina el historial con la misma horquilla [10, 100]', function (string $limit, int $porPagina) {
    $carrier = Carrier::factory()->create();
    $pilot = pilotLinkedTo($carrier);

    CarrierPilotSalaryHistory::factory()->count(12)->create(['carrier_pilot_id' => $pilot->id]);

    asUser($carrier->owner)->getJson("/api/pilots/{$pilot->user_id}/salary-history?limit={$limit}")
        ->assertOk()
        ->assertJsonCount($porPagina, 'data')
        ->assertJson([
            'total' => 12,
            'currentPage' => 1,
            'lastPage' => 2,
        ])
        ->assertJsonStructure([
            'statusCode',
            'message',
            'data' => [['id', 'previousSalary', 'newSalary', 'changedById', 'changedByName', 'changedAt']],
            'total',
            'currentPage',
            'lastPage',
        ]);
})->with([
    'limit=10' => ['10', 10],
    'limit=1 se acota a 10' => ['1', 10],
]);

it('rechaza con 403 a un carrier que lee el historial de un piloto de otra empresa', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = pilotLinkedTo(Carrier::factory()->create());

    asUser($carrier->owner)->getJson("/api/pilots/{$ajeno->user_id}/salary-history")
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No puedes acceder a un piloto que no pertenece a tu empresa transportista',
            'data' => null,
        ]);
});

it('devuelve a un carrier el historial de un piloto suyo', function () {
    $carrier = Carrier::factory()->create();
    $pilot = pilotLinkedTo($carrier);

    CarrierPilotSalaryHistory::factory()->count(2)->create(['carrier_pilot_id' => $pilot->id]);

    asUser($carrier->owner)->getJson("/api/pilots/{$pilot->user_id}/salary-history")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('devuelve 404 al pedir el historial de un user_id que no existe', function () {
    asUser(userWithRole(UserRole::Administrator))->getJson('/api/pilots/99999/salary-history')
        ->assertNotFound()
        ->assertExactJson([
            'statusCode' => 404,
            'message' => 'El piloto no existe o no está vinculado a ninguna empresa transportista',
            'data' => null,
        ]);
});

/*
|--------------------------------------------------------------------------
| SPEC 25 — Los documentos del piloto en el listado
|--------------------------------------------------------------------------
*/

it('devuelve las dos fotos del piloto en cada elemento del listado, con las nueve claves del recurso', function () {
    $carrier = Carrier::factory()->create();
    $pilot = pilotLinkedTo($carrier, 4500);
    $documents = PilotDocument::factory()->create(['user_id' => $pilot->user_id]);

    $response = asUser($carrier->owner)->getJson('/api/pilots')->assertOk();

    expect(array_keys($response->json('data.0')))
        ->toBe(['id', 'name', 'email', 'carrierId', 'carrierName', 'salary', 'joinedAt', 'dpiImage', 'licenseImage'])
        ->and($response->json('data.0.dpiImage'))->toBe(Storage::url($documents->dpi_image))
        ->toStartWith('http')
        ->toContain('pilot-documents/')
        ->and($response->json('data.0.licenseImage'))->toBe(Storage::url($documents->license_image))
        ->toStartWith('http');
});

it('devuelve las dos fotos en null, presentes y no ausentes, para un piloto anterior a la spec', function () {
    $carrier = Carrier::factory()->create();
    pilotLinkedTo($carrier, 4500);

    $response = asUser($carrier->owner)->getJson('/api/pilots')->assertOk();

    expect(array_keys($response->json('data.0')))->toContain('dpiImage', 'licenseImage')
        ->and($response->json('data.0.dpiImage'))->toBeNull()
        ->and($response->json('data.0.licenseImage'))->toBeNull();
});

it('devuelve las fotos de cada piloto, y solo las suyas, cuando conviven con y sin documentos', function () {
    $carrier = Carrier::factory()->create();

    $conDocumentos = pilotLinkedTo($carrier);
    $sinDocumentos = pilotLinkedTo($carrier);

    $documents = PilotDocument::factory()->create(['user_id' => $conDocumentos->user_id]);

    $data = collect(asUser($carrier->owner)->getJson('/api/pilots')->assertOk()->json('data'))->keyBy('id');

    expect($data[$conDocumentos->user_id]['dpiImage'])->toBe(Storage::url($documents->dpi_image))
        ->and($data[$conDocumentos->user_id]['licenseImage'])->toBe(Storage::url($documents->license_image))
        ->and($data[$sinDocumentos->user_id]['dpiImage'])->toBeNull()
        ->and($data[$sinDocumentos->user_id]['licenseImage'])->toBeNull();
});

it('ejecuta el mismo número de consultas al listar tres pilotos con documentos que al listar veinte', function () {
    $carrier = Carrier::factory()->create();

    $listarComoAdministrador = function (): int {
        /** El token se emite antes de escuchar: sus claims consultan la empresa del usuario. */
        $token = JWTAuth::fromUser(userWithRole(UserRole::Administrator));

        resetAuthState();

        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        test()->withToken($token)->getJson('/api/pilots')->assertOk();

        return count($queries);
    };

    $conDocumentos = function (Carrier $carrier, int $count): void {
        for ($i = 0; $i < $count; $i++) {
            PilotDocument::factory()->create(['user_id' => pilotLinkedTo($carrier)->user_id]);
        }
    };

    $conDocumentos($carrier, 3);
    $conTres = $listarComoAdministrador();

    $conDocumentos($carrier, 17);
    $conVeinte = $listarComoAdministrador();

    /** El eager load de `user.pilotDocument` hace el coste fijo: N no aparece en la cuenta. */
    expect($conVeinte)->toBe($conTres);
});

it('carga los documentos de veinte pilotos con una sola consulta a pilot_documents', function () {
    $carrier = Carrier::factory()->create();

    for ($i = 0; $i < 20; $i++) {
        PilotDocument::factory()->create(['user_id' => pilotLinkedTo($carrier)->user_id]);
    }

    $token = JWTAuth::fromUser(userWithRole(UserRole::Administrator));

    resetAuthState();

    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = test()->withToken($token)->getJson('/api/pilots')->assertOk()->assertJsonCount(20, 'data');

    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'from "pilot_documents"')))->toHaveCount(1)
        ->and(collect($response->json('data'))->every(fn (array $pilot): bool => is_string($pilot['dpiImage'])))->toBeTrue();
});

/**
 * GET /api/carriers/me/pilots (SPEC 03) quedó intacto: son dos recursos distintos a
 * propósito, cruzables por `id`, y el de la empresa no gana las fotos.
 */
it('deja GET /api/carriers/me/pilots con exactamente las mismas cuatro claves de siempre', function () {
    $carrier = Carrier::factory()->create();
    $pilot = pilotLinkedTo($carrier, 4500);

    PilotDocument::factory()->create(['user_id' => $pilot->user_id]);

    $response = asUser($carrier->owner)->getJson('/api/carriers/me/pilots')->assertOk();

    expect(array_keys($response->json('data.0')))->toBe(['id', 'name', 'email', 'joinedAt'])
        ->and($response->json('data.0'))->not->toHaveKeys(['dpiImage', 'licenseImage', 'salary']);
});
