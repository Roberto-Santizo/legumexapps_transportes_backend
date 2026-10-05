<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Storage\FileStorageServiceInterface;
use App\Interfaces\TripEmergencyExpense\TripEmergencyExpenseServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\TripEmergencyExpense;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripEmergencyExpense\TripEmergencyExpenseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Tests\Doubles\InMemoryFileStorageService;

/**
 * Resolve the service through the container, which checks the Provider binding too.
 */
function tripEmergencyExpenseService(): TripEmergencyExpenseServiceInterface
{
    return app(TripEmergencyExpenseServiceInterface::class);
}

function tripEmergencyExpenseServiceUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

/**
 * A trip already taken by a company, with its assigned pilot and the owner that took it.
 *
 * @param  array<string, mixed>  $attributes
 * @return array{trip: Trip, pilot: User, owner: User}
 */
function tripEmergencyExpenseServiceScene(string $state = 'inRoute', array $attributes = []): array
{
    $trip = Trip::factory()->{$state}()->create($attributes);

    return [
        'trip' => $trip,
        'pilot' => User::findOrFail($trip->pilot_id),
        'owner' => User::findOrFail($trip->assigned_by),
    ];
}

/**
 * The owner of a company that has nothing to do with the trip under test.
 */
function tripEmergencyExpenseServiceStranger(): User
{
    $carrier = Carrier::factory()->create();
    Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    return $carrier->owner;
}

/**
 * Bind and return the in-memory storage double, so a test can read what was uploaded.
 */
function tripEmergencyExpenseServiceStorage(): InMemoryFileStorageService
{
    $storage = new InMemoryFileStorageService;

    app()->instance(FileStorageServiceInterface::class, $storage);

    return $storage;
}

/*
|--------------------------------------------------------------------------
| Binding
|--------------------------------------------------------------------------
*/

it('resuelve el contrato del dominio contra su implementación', function () {
    expect(tripEmergencyExpenseService())->toBeInstanceOf(TripEmergencyExpenseService::class);
});

/*
|--------------------------------------------------------------------------
| create()
|--------------------------------------------------------------------------
*/

it('crea el gasto con el autor del usuario dado y sin comprobante', function () {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseServiceScene();

    $expense = tripEmergencyExpenseService()->create($owner, $trip->id, ['amount' => 120, 'description' => ' Grúa ']);

    expect($expense->trip_id)->toBe($trip->id)
        ->and($expense->registered_by)->toBe($owner->id)
        ->and($expense->description)->toBe('Grúa')
        ->and($expense->receipt)->toBeNull()
        ->and($expense->relationLoaded('registeredBy'))->toBeTrue();
});

it('sube el comprobante con storeUpload bajo trip-emergency-expenses', function () {
    $storage = tripEmergencyExpenseServiceStorage();
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseServiceScene();

    $expense = tripEmergencyExpenseService()->create($owner, $trip->id, [
        'amount' => 120,
        'description' => 'Grúa',
        'receipt' => UploadedFile::fake()->create('recibo.pdf', 10, 'application/pdf'),
    ]);

    expect($expense->receipt)->toStartWith('trip-emergency-expenses/')->toEndWith('.pdf')
        ->and($storage->keys())->toBe([$expense->receipt]);
});

it('borra el comprobante recién subido si la fila no se puede guardar', function () {
    $storage = tripEmergencyExpenseServiceStorage();
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseServiceScene();

    /** Un monto que desborda el decimal(10,2): sin FormRequest delante, revienta en Postgres. */
    expect(fn () => tripEmergencyExpenseService()->create($owner, $trip->id, [
        'amount' => 1000000000,
        'description' => 'Grúa',
        'receipt' => UploadedFile::fake()->create('recibo.pdf', 10, 'application/pdf'),
    ]))->toThrow(QueryException::class);

    /** Tras el error de Postgres la transacción del test queda abortada: solo se mira el almacenamiento. */
    expect($storage->keys())->toBe([]);
});

it('aplica las cuatro guardas del alta en su orden', function (Closure $scene, string $error, string $message) {
    [$user, $tripId] = $scene();
    $storage = tripEmergencyExpenseServiceStorage();

    expect(fn () => tripEmergencyExpenseService()->create($user, $tripId, [
        'amount' => 10,
        'description' => 'Grúa',
        'receipt' => UploadedFile::fake()->create('recibo.pdf', 10, 'application/pdf'),
    ]))->toThrow($error, $message);

    /** Ninguna guarda deja un archivo subido. */
    expect($storage->keys())->toBe([]);
})->with([
    'inexistente' => [fn () => [tripEmergencyExpenseServiceStranger(), 99999], NotFoundError::class, 'El viaje no existe'],
    'borrado y ajeno' => [fn () => [tripEmergencyExpenseServiceStranger(), Trip::factory()->inRoute()->create(['deleted_at' => now()])->id], BadRequestError::class, 'El viaje ya fue eliminado'],
    'ajeno' => [fn () => [tripEmergencyExpenseServiceStranger(), Trip::factory()->inRoute()->create()->id], ForbiddenError::class, 'No puedes registrar gastos emergentes en un viaje que no tomó tu empresa transportista'],
    'sin asignar, transportista' => [fn () => [tripEmergencyExpenseServiceStranger(), Trip::factory()->create()->id], ForbiddenError::class, 'No puedes registrar gastos emergentes en un viaje que no tomó tu empresa transportista'],
    'sin asignar, administrador' => [fn () => [tripEmergencyExpenseServiceUser(UserRole::Administrator), Trip::factory()->create()->id], BadRequestError::class, 'El viaje aún no fue asignado'],
    'sin empresa' => [fn () => [tripEmergencyExpenseServiceUser(UserRole::Carrier), Trip::factory()->inRoute()->create()->id], ForbiddenError::class, 'No perteneces a ninguna empresa transportista'],
    'pendiente' => [function () {
        $trip = Trip::factory()->assigned()->create();

        return [User::findOrFail($trip->assigned_by), $trip->id];
    }, BadRequestError::class, 'Solo se pueden registrar gastos emergentes en un viaje en ruta'],
    'finalizado' => [function () {
        $trip = Trip::factory()->finished()->create();

        return [User::findOrFail($trip->assigned_by), $trip->id];
    }, BadRequestError::class, 'Solo se pueden registrar gastos emergentes en un viaje en ruta'],
]);

/*
|--------------------------------------------------------------------------
| update()
|--------------------------------------------------------------------------
*/

it('reemplaza el comprobante y borra el anterior después de guardar', function () {
    $storage = tripEmergencyExpenseServiceStorage();
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseServiceScene();

    $expense = tripEmergencyExpenseService()->create($owner, $trip->id, [
        'amount' => 10,
        'description' => 'Grúa',
        'receipt' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
    ]);
    $anterior = $expense->receipt;

    $updated = tripEmergencyExpenseService()->update($owner, $expense->id, [
        'receipt' => UploadedFile::fake()->image('b.png'),
    ]);

    expect($updated->receipt)->not->toBe($anterior)->toEndWith('.png')
        ->and($storage->keys())->toBe([$updated->receipt]);
});

it('quita el comprobante con removeReceipt y lo borra del almacenamiento', function () {
    $storage = tripEmergencyExpenseServiceStorage();
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseServiceScene();

    $expense = tripEmergencyExpenseService()->create($owner, $trip->id, [
        'amount' => 10,
        'description' => 'Grúa',
        'receipt' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
    ]);

    $updated = tripEmergencyExpenseService()->update($owner, $expense->id, ['removeReceipt' => true]);

    expect($updated->receipt)->toBeNull()
        ->and($storage->keys())->toBe([]);
});

it('no escribe nada con un payload vacío ni toca trip_id ni registered_by', function () {
    ['trip' => $trip, 'owner' => $owner, 'pilot' => $pilot] = tripEmergencyExpenseServiceScene();
    $expense = TripEmergencyExpense::factory()->create(['trip_id' => $trip->id]);
    $antes = $expense->fresh()->updated_at;

    $this->travel(1)->hour();

    $updated = tripEmergencyExpenseService()->update($owner, $expense->id, [
        'trip_id' => Trip::factory()->inRoute()->create()->id,
        'registered_by' => $pilot->id,
    ]);

    expect($updated->trip_id)->toBe($trip->id)
        ->and($updated->registered_by)->toBe($owner->id)
        ->and($expense->fresh()->updated_at->equalTo($antes))->toBeTrue();
});

it('aplica las cuatro guardas de update() y delete() en su orden', function (string $method, Closure $scene, string $error, string $message) {
    [$user, $expenseId] = $scene();

    $call = $method === 'update'
        ? fn () => tripEmergencyExpenseService()->update($user, $expenseId, ['amount' => 1])
        : fn () => tripEmergencyExpenseService()->delete($user, $expenseId);

    expect($call)->toThrow($error, $message);
})->with(['update', 'delete'])->with([
    'inexistente' => [fn () => [tripEmergencyExpenseServiceStranger(), 99999], NotFoundError::class, 'El gasto emergente no existe'],
    'viaje borrado y ajeno' => [function () {
        $expense = TripEmergencyExpense::factory()->create();
        $expense->trip->delete();

        return [tripEmergencyExpenseServiceStranger(), $expense->id];
    }, BadRequestError::class, 'El viaje ya fue eliminado'],
    'ajeno' => [fn () => [tripEmergencyExpenseServiceStranger(), TripEmergencyExpense::factory()->create()->id], ForbiddenError::class, 'No puedes registrar gastos emergentes en un viaje que no tomó tu empresa transportista'],
    'pendiente' => [function () {
        $expense = TripEmergencyExpense::factory()->create();
        $expense->trip->update(['status' => TripStatus::Pending]);

        return [User::findOrFail($expense->trip->assigned_by), $expense->id];
    }, BadRequestError::class, 'No se pueden modificar los gastos emergentes de un viaje pendiente'],
]);

/*
|--------------------------------------------------------------------------
| delete()
|--------------------------------------------------------------------------
*/

it('borra la fila y su comprobante y devuelve el gasto borrado', function () {
    $storage = tripEmergencyExpenseServiceStorage();
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseServiceScene('finished');

    $expense = TripEmergencyExpense::factory()->create(['trip_id' => $trip->id]);
    $expense->update(['receipt' => $storage->storeUpload(UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), 'trip-emergency-expenses')]);

    $deleted = tripEmergencyExpenseService()->delete($owner, $expense->id);

    expect($deleted->id)->toBe($expense->id)
        ->and($deleted->relationLoaded('registeredBy'))->toBeTrue()
        ->and(TripEmergencyExpense::find($expense->id))->toBeNull()
        ->and($storage->keys())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| getTripEmergencyExpenses()
|--------------------------------------------------------------------------
*/

it('devuelve una Collection sin limit y un paginador con limit, con el total de todas las filas', function () {
    ['trip' => $trip, 'owner' => $owner] = tripEmergencyExpenseServiceScene();

    TripEmergencyExpense::factory()->count(12)->create(['trip_id' => $trip->id, 'amount' => 10]);

    $sinLimit = tripEmergencyExpenseService()->getTripEmergencyExpenses($owner, $trip->id, []);
    $conLimit = tripEmergencyExpenseService()->getTripEmergencyExpenses($owner, $trip->id, ['limit' => '10']);

    expect($sinLimit['emergencyExpenses'])->toBeInstanceOf(Collection::class)->toHaveCount(12)
        ->and($sinLimit['totalAmount'])->toBe('120.00')
        ->and($conLimit['emergencyExpenses'])->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($conLimit['emergencyExpenses']->items())->toHaveCount(10)
        ->and($conLimit['totalAmount'])->toBe('120.00');
});

it('rechaza a shipment aunque llegue al service sin pasar por la ruta', function () {
    ['trip' => $trip] = tripEmergencyExpenseServiceScene();

    expect(fn () => tripEmergencyExpenseService()->getTripEmergencyExpenses(tripEmergencyExpenseServiceUser(UserRole::Shipment), $trip->id, []))
        ->toThrow(ForbiddenError::class, 'No tienes permisos para consultar los gastos emergentes de un viaje');
});

it('deja leer al piloto asignado y rechaza al piloto ajeno', function () {
    ['trip' => $trip, 'pilot' => $pilot] = tripEmergencyExpenseServiceScene();

    TripEmergencyExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 5]);

    expect(tripEmergencyExpenseService()->getTripEmergencyExpenses($pilot, $trip->id, [])['totalAmount'])->toBe('5.00');

    expect(fn () => tripEmergencyExpenseService()->getTripEmergencyExpenses(tripEmergencyExpenseServiceUser(UserRole::Pilot), $trip->id, []))
        ->toThrow(ForbiddenError::class);
});
