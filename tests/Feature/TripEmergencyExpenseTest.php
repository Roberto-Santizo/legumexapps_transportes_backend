<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripEmergencyExpense;
use App\Models\TripExpense;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * The four routes of the domain as method and URI, for the middleware datasets.
 *
 * The first two are nested under `{trip}`; correcting and deleting are not, because the
 * id of the expense already identifies its trip.
 *
 * @return array<string, array{string, string}>
 */
function tripEmergencyExpenseEndpoints(): array
{
    return [
        'store' => ['POST', '/api/trips/1/emergency-expenses'],
        'index' => ['GET', '/api/trips/1/emergency-expenses'],
        'update' => ['PATCH', '/api/trip-emergency-expenses/1'],
        'destroy' => ['DELETE', '/api/trip-emergency-expenses/1'],
    ];
}

/**
 * The roles `role:carrier,administrator` keeps out of the three writing endpoints.
 *
 * @return array<string, UserRole>
 */
function tripEmergencyExpenseNonWriterRoles(): array
{
    return [
        'manager' => UserRole::Manager,
        'pilot' => UserRole::Pilot,
        'export' => UserRole::Export,
        'user' => UserRole::User,
        'shipment' => UserRole::Shipment,
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
     * The JWT singletons survive between calls of the same test, so the guard state is
     * dropped before handing the fresh token over.
     */
    function asUser(User $user): TestCase
    {
        resetAuthState();

        return test()->withToken(JWTAuth::fromUser($user))->withHeader('Accept', 'application/json');
    }
}

/**
 * A trip already taken by a company, with its assigned pilot and the owner that took it.
 *
 * Defaults to `inRoute`: the only state an emergency expense is registered on.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{trip: Trip, pilot: User, owner: User}
 */
function tripEmergencyExpenseScene(string $state = 'inRoute', array $attributes = []): array
{
    $trip = Trip::factory()->{$state}()->create($attributes);

    return [
        'trip' => $trip,
        'pilot' => User::findOrFail($trip->pilot_id),
        'owner' => User::findOrFail($trip->assigned_by),
    ];
}

/**
 * The owner of another company, with a vehicle of its own.
 */
function tripEmergencyExpenseStranger(): User
{
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    return $carrier->owner;
}

/**
 * A valid store payload with the fields `StoreTripEmergencyExpenseRequest` declares.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tripEmergencyExpensePayload(array $overrides = []): array
{
    return array_merge([
        'amount' => 450,
        'description' => 'Reparación de llanta pinchada',
    ], $overrides);
}

/**
 * A receipt stored on the fake disk, the way the service would have left it.
 */
function tripEmergencyExpenseStoredReceipt(string $extension = 'pdf'): string
{
    $key = 'trip-emergency-expenses/'.fake()->uuid().'.'.$extension;

    Storage::put($key, 'comprobante');

    return $key;
}

/**
 * The nine keys `TripEmergencyExpenseResource` promises, in the order it declares them.
 *
 * @return array<int, string>
 */
function tripEmergencyExpenseResourceKeys(): array
{
    return ['id', 'tripId', 'amount', 'description', 'receiptUrl', 'receiptType', 'registeredByName', 'createdAt', 'updatedAt'];
}

/*
|--------------------------------------------------------------------------
| Middlewares: jwt.auth y role
|--------------------------------------------------------------------------
*/

it('rechaza con 401 las cuatro rutas de gastos emergentes sin token', function (string $method, string $uri) {
    $this->json($method, $uri)
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
})->with(tripEmergencyExpenseEndpoints());

it('rechaza con 403 del middleware a quien no es transportista ni administrador al registrar', function (UserRole $role) {
    ['trip' => $trip] = tripEmergencyExpenseScene();

    asUser(userWithRole($role))->postJson("/api/trips/{$trip->id}/emergency-expenses", tripEmergencyExpensePayload())
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);

    expect(TripEmergencyExpense::count())->toBe(0);
})->with(tripEmergencyExpenseNonWriterRoles());

it('rechaza con 403 del middleware a quien no es transportista ni administrador al corregir o borrar', function (UserRole $role, string $method) {
    $expense = TripEmergencyExpense::factory()->create(['amount' => 100]);

    asUser(userWithRole($role))->json($method, "/api/trip-emergency-expenses/{$expense->id}", ['amount' => 999])
        ->assertForbidden()
        ->assertJsonPath('message', 'No tienes permisos para acceder a este recurso');

    expect($expense->fresh()->amount)->toBe('100.00');
})->with(tripEmergencyExpenseNonWriterRoles())->with(['PATCH', 'DELETE']);

it('rechaza con 403 del middleware a shipment en el listado', function () {
    ['trip' => $trip] = tripEmergencyExpenseScene();

    asUser(userWithRole(UserRole::Shipment))->getJson("/api/trips/{$trip->id}/emergency-expenses")
        ->assertForbidden()
        ->assertJsonPath('message', 'No tienes permisos para acceder a este recurso');
});

/*
|--------------------------------------------------------------------------
| POST /api/trips/{trip}/emergency-expenses — las cuatro guardas, en su orden
|--------------------------------------------------------------------------
*/

it('registra el gasto emergente sin comprobante del transportista que tomó el viaje', function () {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();

    $response = asUser($owner)->postJson("/api/trips/{$trip->id}/emergency-expenses", tripEmergencyExpensePayload(['amount' => 450.5]))
        ->assertCreated()
        ->assertJsonPath('statusCode', 201)
        ->assertJsonPath('message', 'Gasto emergente registrado correctamente')
        ->assertJsonPath('data.tripId', $trip->id)
        ->assertJsonPath('data.amount', '450.50')
        ->assertJsonPath('data.description', 'Reparación de llanta pinchada')
        ->assertJsonPath('data.receiptUrl', null)
        ->assertJsonPath('data.receiptType', null)
        ->assertJsonPath('data.registeredByName', $owner->name);

    $this->assertDatabaseHas('trip_emergency_expenses', [
        'trip_id' => $trip->id,
        'amount' => '450.50',
        'description' => 'Reparación de llanta pinchada',
        'receipt' => null,
        /** El autor sale del usuario autenticado, nunca del body. */
        'registered_by' => $owner->id,
    ]);

    expect(array_keys($response->json('data')))->toBe(tripEmergencyExpenseResourceKeys())
        ->and($response->json('data.createdAt'))->toMatch('/^\d{2}-\d{2}-\d{4} \d{2}:\d{2}:\d{2} (AM|PM)$/');
});

it('guarda el comprobante tal cual llega, bajo trip-emergency-expenses/, con su tipo', function (UploadedFile $file, string $extension) {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();

    $response = asUser($owner)->post("/api/trips/{$trip->id}/emergency-expenses", tripEmergencyExpensePayload([
        'receipt' => $file,
    ]))
        ->assertCreated()
        ->assertJsonPath('data.receiptType', $extension);

    $key = TripEmergencyExpense::query()->value('receipt');

    expect($key)->toMatch('#^trip-emergency-expenses/[0-9a-f-]{36}\.'.$extension.'$#')
        ->and($response->json('data.receiptUrl'))->toStartWith('http')->toEndWith($key);

    Storage::assertExists($key);

    /** Byte a byte: el comprobante no pasa por el procesador de imágenes. */
    expect(Storage::get($key))->toBe(file_get_contents($file->getRealPath()));
})->with([
    'jpg' => [fn () => UploadedFile::fake()->image('recibo.jpg', 1600, 900), 'jpg'],
    'png' => [fn () => UploadedFile::fake()->image('recibo.png', 1600, 900), 'png'],
    'pdf' => [fn () => UploadedFile::fake()->create('recibo.pdf', 40, 'application/pdf'), 'pdf'],
]);

it('guarda la descripción con solo trim', function () {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();

    asUser($owner)->postJson("/api/trips/{$trip->id}/emergency-expenses", tripEmergencyExpensePayload([
        'description' => '  Grúa  desde el km 85  ',
    ]))->assertCreated()->assertJsonPath('data.description', 'Grúa  desde el km 85');

    expect(TripEmergencyExpense::firstOrFail()->description)->toBe('Grúa  desde el km 85');
});

it('deja registrar a cualquier usuario de la empresa que tomó el viaje', function () {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();

    $companero = userWithRole(UserRole::Carrier);
    $owner->currentCarrier()->pilots()->attach($companero);

    asUser($companero)->postJson("/api/trips/{$trip->id}/emergency-expenses", tripEmergencyExpensePayload())->assertCreated();

    $this->assertDatabaseHas('trip_emergency_expenses', ['trip_id' => $trip->id, 'registered_by' => $companero->id]);
});

it('rechaza con 404 registrar sobre un viaje que no existe', function () {
    asUser(tripEmergencyExpenseStranger())->postJson('/api/trips/99999/emergency-expenses', tripEmergencyExpensePayload())
        ->assertNotFound()
        ->assertJsonPath('message', 'El viaje no existe');
});

it('rechaza con 400, y no con 403, un viaje borrado que además es de otra empresa', function () {
    ['trip' => $trip] = tripEmergencyExpenseScene('inRoute', ['deleted_at' => now()]);

    asUser(tripEmergencyExpenseStranger())->postJson("/api/trips/{$trip->id}/emergency-expenses", tripEmergencyExpensePayload())
        ->assertBadRequest()
        ->assertJsonPath('message', 'El viaje ya fue eliminado');

    expect(TripEmergencyExpense::count())->toBe(0);
});

it('rechaza con 403 al transportista sobre un viaje sin asignar o de otra empresa', function (bool $sinAsignar) {
    $trip = $sinAsignar ? Trip::factory()->create() : tripEmergencyExpenseScene()['trip'];

    asUser(tripEmergencyExpenseStranger())->postJson("/api/trips/{$trip->id}/emergency-expenses", tripEmergencyExpensePayload())
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes registrar gastos emergentes en un viaje que no tomó tu empresa transportista');

    expect(TripEmergencyExpense::count())->toBe(0);
})->with([
    'sin asignar' => true,
    'de otra empresa' => false,
]);

it('rechaza con 403 al transportista que no pertenece a ninguna empresa', function () {
    ['trip' => $trip] = tripEmergencyExpenseScene();

    asUser(userWithRole(UserRole::Carrier))->postJson("/api/trips/{$trip->id}/emergency-expenses", tripEmergencyExpensePayload())
        ->assertForbidden()
        ->assertJsonPath('message', 'No perteneces a ninguna empresa transportista');
});

it('rechaza con 400 al administrador sobre un viaje sin asignar', function () {
    $trip = Trip::factory()->create();

    asUser(userWithRole(UserRole::Administrator))->postJson("/api/trips/{$trip->id}/emergency-expenses", tripEmergencyExpensePayload())
        ->assertBadRequest()
        ->assertJsonPath('message', 'El viaje aún no fue asignado');

    expect(TripEmergencyExpense::count())->toBe(0);
});

it('deja al administrador registrar en un viaje en ruta de cualquier empresa', function () {
    ['trip' => $trip] = tripEmergencyExpenseScene();
    $administrator = userWithRole(UserRole::Administrator);

    asUser($administrator)->postJson("/api/trips/{$trip->id}/emergency-expenses", tripEmergencyExpensePayload())
        ->assertCreated()
        ->assertJsonPath('data.registeredByName', $administrator->name);
});

it('rechaza con 400 registrar con el viaje pendiente o finalizado', function (string $estado) {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene($estado);

    asUser($owner)->postJson("/api/trips/{$trip->id}/emergency-expenses", tripEmergencyExpensePayload([
        'receipt' => UploadedFile::fake()->create('recibo.pdf', 40, 'application/pdf'),
    ]))
        ->assertBadRequest()
        ->assertJsonPath('message', 'Solo se pueden registrar gastos emergentes en un viaje en ruta');

    /** El archivo no se sube si una guarda rechaza: sin huérfanos en el bucket. */
    expect(TripEmergencyExpense::count())->toBe(0)
        ->and(Storage::allFiles())->toBe([]);
})->with([
    'pendiente' => 'assigned',
    'finalizado' => 'finished',
]);

it('ignora tripId y registeredBy en el cuerpo', function () {
    ['trip' => $trip, 'owner' => $owner, 'pilot' => $pilot] = tripEmergencyExpenseScene();
    $otroViaje = Trip::factory()->inRoute()->create();

    asUser($owner)->postJson("/api/trips/{$trip->id}/emergency-expenses", tripEmergencyExpensePayload([
        'tripId' => $otroViaje->id,
        'trip_id' => $otroViaje->id,
        'registeredBy' => $pilot->id,
        'registered_by' => $pilot->id,
    ]))->assertCreated();

    $expense = TripEmergencyExpense::firstOrFail();

    expect($expense->trip_id)->toBe($trip->id)
        ->and($expense->registered_by)->toBe($owner->id);
});

/*
|--------------------------------------------------------------------------
| POST: validación del cuerpo
|--------------------------------------------------------------------------
*/

it('rechaza con 422 un cuerpo inválido en el gasto emergente', function (array $payload, string $campo, string $mensaje) {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();

    asUser($owner)->postJson("/api/trips/{$trip->id}/emergency-expenses", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo])
        ->assertJsonFragment([$mensaje]);

    expect(TripEmergencyExpense::count())->toBe(0);
})->with([
    'sin monto' => [['description' => 'Grúa'], 'amount', 'El monto es obligatorio'],
    'monto no numérico' => [['amount' => 'cuatrocientos', 'description' => 'Grúa'], 'amount', 'El monto debe ser un número'],
    'monto en cero' => [['amount' => 0, 'description' => 'Grúa'], 'amount', 'El monto debe ser mayor a 0'],
    'monto negativo' => [['amount' => -5, 'description' => 'Grúa'], 'amount', 'El monto debe ser mayor a 0'],
    'monto desbordado' => [['amount' => 100000000, 'description' => 'Grúa'], 'amount', 'El monto no puede superar 99999999.99'],
    'sin descripción' => [['amount' => 100], 'description', 'La descripción es obligatoria'],
    'descripción en blanco' => [['amount' => 100, 'description' => '   '], 'description', 'La descripción es obligatoria'],
    'descripción larga' => [['amount' => 100, 'description' => str_repeat('a', 256)], 'description', 'La descripción no puede superar los 255 caracteres'],
]);

it('rechaza con 422 un comprobante de otro tipo o de más de 3 MB', function (UploadedFile $file, string $mensaje) {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();

    asUser($owner)->post("/api/trips/{$trip->id}/emergency-expenses", tripEmergencyExpensePayload(['receipt' => $file]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['receipt'])
        ->assertJsonFragment([$mensaje]);

    expect(TripEmergencyExpense::count())->toBe(0)
        ->and(Storage::allFiles())->toBe([]);
})->with([
    'ejecutable' => [fn () => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'), 'El comprobante debe ser un archivo jpg, jpeg, png o pdf'],
    'demasiado grande' => [fn () => UploadedFile::fake()->create('enorme.pdf', 4096, 'application/pdf'), 'El comprobante no puede pesar más de 3 MB'],
]);

/*
|--------------------------------------------------------------------------
| PATCH /api/trip-emergency-expenses/{tripEmergencyExpense}
|--------------------------------------------------------------------------
*/

it('corrige monto y descripción con el viaje en ruta o finalizado', function (string $estado) {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene($estado);
    $expense = TripEmergencyExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 100, 'description' => 'Grúa']);

    asUser($owner)->patchJson("/api/trip-emergency-expenses/{$expense->id}", [
        'amount' => 175.25,
        'description' => '  Grúa y peaje  ',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Gasto emergente actualizado correctamente')
        ->assertJsonPath('data.amount', '175.25')
        ->assertJsonPath('data.description', 'Grúa y peaje');

    expect($expense->fresh()->amount)->toBe('175.25');
})->with([
    'en ruta' => 'inRoute',
    'finalizado' => 'finished',
]);

it('ignora tripId, trip_id y registered_by en el PATCH', function () {
    ['trip' => $trip, 'owner' => $owner, 'pilot' => $pilot] = tripEmergencyExpenseScene();
    $expense = TripEmergencyExpense::factory()->create(['trip_id' => $trip->id]);
    $otroViaje = Trip::factory()->inRoute()->create();

    asUser($owner)->patchJson("/api/trip-emergency-expenses/{$expense->id}", [
        'tripId' => $otroViaje->id,
        'trip_id' => $otroViaje->id,
        'registered_by' => $pilot->id,
        'registeredBy' => $pilot->id,
    ])->assertOk()->assertJsonPath('data.tripId', $trip->id);

    expect($expense->fresh()->trip_id)->toBe($trip->id)
        ->and($expense->fresh()->registered_by)->toBe($owner->id);
});

it('responde 200 sin escribir con un cuerpo vacío', function () {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();
    $expense = TripEmergencyExpense::factory()->create(['trip_id' => $trip->id]);
    $antes = $expense->fresh()->updated_at;

    $this->travel(1)->hour();

    asUser($owner)->patchJson("/api/trip-emergency-expenses/{$expense->id}", [])->assertOk();

    expect($expense->fresh()->updated_at->equalTo($antes))->toBeTrue();
});

it('reemplaza el comprobante con POST y _method=PATCH y borra el anterior del disco', function () {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();
    $anterior = tripEmergencyExpenseStoredReceipt();
    $expense = TripEmergencyExpense::factory()->create(['trip_id' => $trip->id, 'receipt' => $anterior]);

    asUser($owner)->post("/api/trip-emergency-expenses/{$expense->id}", [
        '_method' => 'PATCH',
        'receipt' => UploadedFile::fake()->image('nuevo.png'),
    ])->assertOk()->assertJsonPath('data.receiptType', 'png');

    $nuevo = $expense->fresh()->receipt;

    expect($nuevo)->not->toBe($anterior)->toStartWith('trip-emergency-expenses/');

    Storage::assertExists($nuevo);
    Storage::assertMissing($anterior);
});

it('quita el comprobante con removeReceipt=true y lo borra del disco', function (mixed $valor) {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();
    $anterior = tripEmergencyExpenseStoredReceipt();
    $expense = TripEmergencyExpense::factory()->create(['trip_id' => $trip->id, 'receipt' => $anterior]);

    asUser($owner)->post("/api/trip-emergency-expenses/{$expense->id}", ['_method' => 'PATCH', 'removeReceipt' => $valor])
        ->assertOk()
        ->assertJsonPath('data.receiptUrl', null)
        ->assertJsonPath('data.receiptType', null);

    expect($expense->fresh()->receipt)->toBeNull();

    Storage::assertMissing($anterior);
})->with([
    'booleano' => true,
    'cadena true' => 'true',
    'uno' => '1',
]);

it('rechaza con 422 receipt junto con removeReceipt=true y no toca nada', function () {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();
    $anterior = tripEmergencyExpenseStoredReceipt();
    $expense = TripEmergencyExpense::factory()->create(['trip_id' => $trip->id, 'receipt' => $anterior, 'amount' => 100]);

    asUser($owner)->post("/api/trip-emergency-expenses/{$expense->id}", [
        '_method' => 'PATCH',
        'amount' => 999,
        'removeReceipt' => 'true',
        'receipt' => UploadedFile::fake()->create('nuevo.pdf', 40, 'application/pdf'),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['receipt'])
        ->assertJsonFragment(['No puedes enviar un comprobante y pedir que se quite al mismo tiempo']);

    expect($expense->fresh()->receipt)->toBe($anterior)
        ->and($expense->fresh()->amount)->toBe('100.00')
        ->and(Storage::allFiles())->toBe([$anterior]);
});

it('rechaza con 422 un campo enviado vacío en el PATCH', function (array $payload, string $campo, string $mensaje) {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();
    $expense = TripEmergencyExpense::factory()->create(['trip_id' => $trip->id]);

    asUser($owner)->patchJson("/api/trip-emergency-expenses/{$expense->id}", $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$campo])
        ->assertJsonFragment([$mensaje]);
})->with([
    'monto null' => [['amount' => null], 'amount', 'El monto no puede estar vacío'],
    'monto en cero' => [['amount' => 0], 'amount', 'El monto debe ser mayor a 0'],
    'descripción en blanco' => [['description' => '  '], 'description', 'La descripción no puede estar vacía'],
    'removeReceipt inválido' => [['removeReceipt' => 'quizás'], 'removeReceipt', 'removeReceipt debe ser verdadero o falso'],
]);

/*
|--------------------------------------------------------------------------
| PATCH y DELETE: las cuatro guardas, en su orden
|--------------------------------------------------------------------------
*/

it('rechaza con 404 corregir o borrar un gasto emergente que no existe', function (string $method) {
    asUser(tripEmergencyExpenseStranger())->json($method, '/api/trip-emergency-expenses/99999')
        ->assertNotFound()
        ->assertJsonPath('message', 'El gasto emergente no existe');
})->with(['PATCH', 'DELETE']);

it('rechaza con 400, y no con 403, el gasto de un viaje borrado aunque sea ajeno', function (string $method) {
    $expense = TripEmergencyExpense::factory()->create();
    $expense->trip->delete();

    asUser(tripEmergencyExpenseStranger())->json($method, "/api/trip-emergency-expenses/{$expense->id}", ['amount' => 1])
        ->assertBadRequest()
        ->assertJsonPath('message', 'El viaje ya fue eliminado');

    expect(TripEmergencyExpense::find($expense->id))->not->toBeNull();
})->with(['PATCH', 'DELETE']);

it('rechaza con 403 corregir o borrar el gasto de un viaje de otra empresa', function (string $method) {
    $expense = TripEmergencyExpense::factory()->create(['amount' => 100]);

    asUser(tripEmergencyExpenseStranger())->json($method, "/api/trip-emergency-expenses/{$expense->id}", ['amount' => 999])
        ->assertForbidden()
        ->assertJsonPath('message', 'No puedes registrar gastos emergentes en un viaje que no tomó tu empresa transportista');

    expect($expense->fresh()->amount)->toBe('100.00');
})->with(['PATCH', 'DELETE']);

it('rechaza con 400 corregir o borrar con el viaje devuelto a pendiente', function (string $method) {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();
    $expense = TripEmergencyExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 100]);

    /** El hueco de SPEC 24: el PATCH del administrador devuelve el viaje a pending. */
    $trip->update(['status' => TripStatus::Pending]);

    asUser($owner)->json($method, "/api/trip-emergency-expenses/{$expense->id}", ['amount' => 999])
        ->assertBadRequest()
        ->assertJsonPath('message', 'No se pueden modificar los gastos emergentes de un viaje pendiente');

    expect($expense->fresh()->amount)->toBe('100.00');
})->with(['PATCH', 'DELETE']);

it('deja al administrador corregir y borrar el gasto de cualquier empresa', function () {
    $administrator = userWithRole(UserRole::Administrator);
    $expense = TripEmergencyExpense::factory()->create();

    asUser($administrator)->patchJson("/api/trip-emergency-expenses/{$expense->id}", ['amount' => 50])->assertOk();
    asUser($administrator)->deleteJson("/api/trip-emergency-expenses/{$expense->id}")->assertOk();

    expect(TripEmergencyExpense::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| DELETE /api/trip-emergency-expenses/{tripEmergencyExpense}
|--------------------------------------------------------------------------
*/

it('borra la fila y su comprobante y responde con las nueve claves', function (string $estado) {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene($estado);
    $key = tripEmergencyExpenseStoredReceipt();
    $expense = TripEmergencyExpense::factory()->create(['trip_id' => $trip->id, 'receipt' => $key, 'amount' => 80]);

    $response = asUser($owner)->deleteJson("/api/trip-emergency-expenses/{$expense->id}")
        ->assertOk()
        ->assertJsonPath('message', 'Gasto emergente eliminado correctamente')
        ->assertJsonPath('data.id', $expense->id)
        ->assertJsonPath('data.amount', '80.00')
        ->assertJsonPath('data.registeredByName', $owner->name);

    expect(array_keys($response->json('data')))->toBe(tripEmergencyExpenseResourceKeys())
        ->and(TripEmergencyExpense::find($expense->id))->toBeNull();

    Storage::assertMissing($key);

    /** Un segundo DELETE es 404: la fila desapareció de verdad. */
    asUser($owner)->deleteJson("/api/trip-emergency-expenses/{$expense->id}")->assertNotFound();
})->with([
    'en ruta' => 'inRoute',
    'finalizado' => 'finished',
]);

/*
|--------------------------------------------------------------------------
| GET /api/trips/{trip}/emergency-expenses
|--------------------------------------------------------------------------
*/

it('lista los gastos del viaje en orden de id ascendente con nueve claves y totalAmount de todos', function () {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();

    $primero = TripEmergencyExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 100.25]);
    $segundo = TripEmergencyExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 249.75]);
    /** El gasto de otro viaje no se cuela en este. */
    TripEmergencyExpense::factory()->create(['amount' => 5000]);

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/emergency-expenses")
        ->assertOk()
        ->assertJsonPath('message', 'Gastos emergentes obtenidos correctamente');

    expect(array_keys($response->json()))->toBe(['statusCode', 'message', 'data', 'totalAmount'])
        ->and(array_column($response->json('data'), 'id'))->toBe([$primero->id, $segundo->id])
        ->and(array_keys($response->json('data.0')))->toBe(tripEmergencyExpenseResourceKeys())
        ->and($response->json('totalAmount'))->toBe('350.00');
});

it('mantiene totalAmount como el total del viaje con limit=10 sobre veinticinco gastos', function () {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();

    TripEmergencyExpense::factory()->count(25)->create(['trip_id' => $trip->id, 'amount' => 10]);

    $response = asUser($owner)->getJson("/api/trips/{$trip->id}/emergency-expenses?limit=10")->assertOk();

    expect($response->json())->toHaveKeys(['statusCode', 'message', 'data', 'total', 'currentPage', 'lastPage', 'totalAmount'])
        ->and($response->json('data'))->toHaveCount(10)
        ->and($response->json('total'))->toBe(25)
        ->and($response->json('totalAmount'))->toBe('250.00');
});

it('acota el tamaño de página a [10, 100]', function (string $limit, int $esperado) {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();

    TripEmergencyExpense::factory()->count(12)->create(['trip_id' => $trip->id]);

    expect(asUser($owner)->getJson("/api/trips/{$trip->id}/emergency-expenses?limit={$limit}")->assertOk()->json('data'))
        ->toHaveCount($esperado);
})->with([
    'por debajo del piso' => ['5', 10],
    'por encima del techo' => ['500', 12],
]);

it('devuelve lista vacía y 0.00 para un viaje sin gastos emergentes', function () {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();

    asUser($owner)->getJson("/api/trips/{$trip->id}/emergency-expenses")
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('totalAmount', '0.00');
});

it('deja leer el listado a quien está dentro del ámbito, incluido el piloto asignado', function (string $actor) {
    ['trip' => $trip, 'owner' => $owner, 'pilot' => $pilot] = tripEmergencyExpenseScene();

    TripEmergencyExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 150]);

    $user = match ($actor) {
        'pilot' => $pilot,
        'carrier' => $owner,
        default => userWithRole(UserRole::from($actor)),
    };

    asUser($user)->getJson("/api/trips/{$trip->id}/emergency-expenses")
        ->assertOk()
        ->assertJsonPath('totalAmount', '150.00');
})->with(['administrator', 'manager', 'export', 'user', 'carrier', 'pilot']);

it('rechaza con 403 al piloto ajeno y al transportista fuera del ámbito', function (bool $esPiloto) {
    ['trip' => $trip] = tripEmergencyExpenseScene();

    $user = $esPiloto ? userWithRole(UserRole::Pilot) : tripEmergencyExpenseStranger();

    asUser($user)->getJson("/api/trips/{$trip->id}/emergency-expenses")->assertForbidden();
})->with([
    'piloto ajeno' => true,
    'transportista ajeno' => false,
]);

it('rechaza con 404 el listado de un viaje inexistente o borrado', function (bool $borrado) {
    $tripId = $borrado ? Trip::factory()->inRoute()->create(['deleted_at' => now()])->id : 99999;

    asUser(userWithRole(UserRole::Administrator))->getJson("/api/trips/{$tripId}/emergency-expenses")
        ->assertNotFound()
        ->assertJsonPath('message', 'El viaje no existe');
})->with([
    'inexistente' => false,
    'borrado' => true,
]);

/*
|--------------------------------------------------------------------------
| Viáticos intactos
|--------------------------------------------------------------------------
*/

it('no mezcla los gastos emergentes con los viáticos del viaje', function () {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseScene();

    TripExpense::factory()->confirmed()->create(['trip_id' => $trip->id, 'amount' => 300]);
    TripEmergencyExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 900]);

    $viaticos = asUser($owner)->getJson("/api/trips/{$trip->id}/expenses")->assertOk();

    expect($viaticos->json('data'))->toHaveCount(1)
        ->and($viaticos->json('totalAmount'))->toBe('300.00');

    asUser($owner)->getJson("/api/trips/{$trip->id}")
        ->assertOk()
        ->assertJsonPath('data.totalExpensesAmount', '300.00');
});
