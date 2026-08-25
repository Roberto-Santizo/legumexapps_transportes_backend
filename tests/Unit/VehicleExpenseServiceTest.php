<?php

use App\Enums\UserRole;
use App\Enums\VehicleExpenseCategory;
use App\Enums\VehicleExpenseNature;
use App\Enums\VehicleStatus;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Storage\FileStorageServiceInterface;
use App\Interfaces\VehicleExpense\VehicleExpenseServiceInterface;
use App\Models\Carrier;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Services\VehicleExpense\VehicleExpenseService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Doubles\InMemoryFileStorageService;

function vehicleExpenseService(): VehicleExpenseServiceInterface
{
    return app(VehicleExpenseServiceInterface::class);
}

function expenseAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

function expenseManager(): User
{
    return User::factory()->create(['role' => UserRole::Manager]);
}

/**
 * A valid payload for createVehicleExpense().
 *
 * @return array<string, mixed>
 */
function expenseServicePayload(int $vehicleId, array $overrides = []): array
{
    return array_merge([
        'vehicle_id' => $vehicleId,
        'category' => VehicleExpenseCategory::Tires->value,
        'nature' => VehicleExpenseNature::Preventive->value,
        'amount' => 1250.5,
        'expense_date' => '2026-08-12',
        'description' => 'Cuatro llantas nuevas, taller El Rodaje, factura A-9912',
        'is_invoiced' => false,
    ], $overrides);
}

it('resuelve la implementación registrada en el provider', function () {
    expect(vehicleExpenseService())->toBeInstanceOf(VehicleExpenseService::class);
});

/*
|--------------------------------------------------------------------------
| Base de datos, modelo y factory
|--------------------------------------------------------------------------
*/

it('crea la tabla vehicle_expenses con sus once columnas', function () {
    expect(Schema::hasTable('vehicle_expenses'))->toBeTrue()
        ->and(Schema::getColumnListing('vehicle_expenses'))->toEqualCanonicalizing([
            'id',
            'vehicle_id',
            'category',
            'nature',
            'amount',
            'expense_date',
            'description',
            'is_invoiced',
            'invoice',
            'registered_by',
            'created_at',
            'updated_at',
        ]);
});

it('produce una fila válida con la factory y sin argumentos', function () {
    $expense = VehicleExpense::factory()->create();

    expect($expense->exists)->toBeTrue()
        ->and($expense->vehicle_id)->not->toBeNull()
        ->and($expense->registered_by)->not->toBeNull()
        ->and($expense->description)->not->toBeEmpty();
});

it('castea los cuatro campos del modelo', function () {
    $expense = VehicleExpense::factory()->create([
        'category' => VehicleExpenseCategory::Brakes,
        'nature' => VehicleExpenseNature::Corrective,
        'amount' => 1500,
        'expense_date' => '2026-07-04',
    ])->fresh();

    expect($expense->category)->toBe(VehicleExpenseCategory::Brakes)
        ->and($expense->nature)->toBe(VehicleExpenseNature::Corrective)
        ->and($expense->amount)->toBe('1500.00')
        ->and($expense->expense_date)->toBeInstanceOf(Carbon::class)
        ->and($expense->expense_date->format('Y-m-d'))->toBe('2026-07-04');
});

it('expone el vehículo y el usuario que registró el gasto', function () {
    $vehicle = Vehicle::factory()->create();
    $user = expenseAdmin();

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $user->id,
    ]);

    expect($expense->vehicle)->toBeInstanceOf(Vehicle::class)
        ->and($expense->vehicle->id)->toBe($vehicle->id)
        ->and($expense->registeredBy)->toBeInstanceOf(User::class)
        ->and($expense->registeredBy->id)->toBe($user->id);
});

it('no añade una relación de gastos al vehículo', function () {
    expect(method_exists(Vehicle::class, 'expenses'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| getVehicleExpenses()
|--------------------------------------------------------------------------
*/

it('devuelve una colección y el acumulado sin limit', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    VehicleExpense::factory()->count(3)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
        'amount' => 100.50,
    ]);

    $result = vehicleExpenseService()->getVehicleExpenses($carrier->owner, ['vehicleId' => $vehicle->id]);

    expect($result['expenses'])->toBeInstanceOf(Collection::class)
        ->and($result['expenses'])->toHaveCount(3)
        ->and($result['totalAmount'])->toBe('301.50');
});

it('devuelve un paginador cuando limit es numérico', function () {
    $vehicle = Vehicle::factory()->create();

    VehicleExpense::factory()->count(12)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => expenseAdmin()->id,
    ]);

    $result = vehicleExpenseService()->getVehicleExpenses(expenseAdmin(), [
        'vehicleId' => $vehicle->id,
        'limit' => '10',
    ]);

    expect($result['expenses'])->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($result['expenses']->perPage())->toBe(10)
        ->and($result['expenses']->total())->toBe(12)
        ->and($result['expenses']->lastPage())->toBe(2);
});

it('devuelve una colección cuando limit no es numérico', function () {
    $vehicle = Vehicle::factory()->create();

    VehicleExpense::factory()->count(3)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => expenseAdmin()->id,
    ]);

    $result = vehicleExpenseService()->getVehicleExpenses(expenseAdmin(), [
        'vehicleId' => $vehicle->id,
        'limit' => 'abc',
    ]);

    expect($result['expenses'])->toBeInstanceOf(Collection::class)->toHaveCount(3);
});

it('acota el tamaño de página de gastos al rango permitido', function (string $limit, int $expected) {
    $vehicle = Vehicle::factory()->create();

    VehicleExpense::factory()->count(2)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => expenseAdmin()->id,
    ]);

    $result = vehicleExpenseService()->getVehicleExpenses(expenseAdmin(), [
        'vehicleId' => $vehicle->id,
        'limit' => $limit,
    ]);

    expect($result['expenses']->perPage())->toBe($expected);
})->with([
    'por debajo del mínimo' => ['1', 10],
    'dentro del rango' => ['25', 25],
    'por encima del máximo' => ['500', 100],
]);

it('acota el listado al vehículo pedido', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);
    $otro = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $propios = VehicleExpense::factory()->count(2)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);
    VehicleExpense::factory()->count(3)->create([
        'vehicle_id' => $otro->id,
        'registered_by' => $carrier->owner->id,
    ]);

    $result = vehicleExpenseService()->getVehicleExpenses($carrier->owner, ['vehicleId' => $vehicle->id]);

    expect($result['expenses']->pluck('id')->all())->toEqualCanonicalizing($propios->pluck('id')->all());
});

it('ordena los gastos por fecha descendente y desempata por id descendente', function () {
    $vehicle = Vehicle::factory()->create();
    $user = expenseAdmin();

    $antiguo = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $user->id,
        'expense_date' => '2026-01-01',
    ]);
    $primeroDelDia = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $user->id,
        'expense_date' => '2026-03-01',
    ]);
    $segundoDelDia = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $user->id,
        'expense_date' => '2026-03-01',
    ]);

    $result = vehicleExpenseService()->getVehicleExpenses($user, ['vehicleId' => $vehicle->id]);

    expect($result['expenses']->pluck('id')->all())
        ->toBe([$segundoDelDia->id, $primeroDelDia->id, $antiguo->id]);
});

it('suma en totalAmount todos los gastos filtrados y no los de la página', function () {
    $vehicle = Vehicle::factory()->create();

    VehicleExpense::factory()->count(12)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => expenseAdmin()->id,
        'amount' => 100.50,
    ]);

    $result = vehicleExpenseService()->getVehicleExpenses(expenseAdmin(), [
        'vehicleId' => $vehicle->id,
        'limit' => '10',
    ]);

    expect($result['expenses'])->toHaveCount(10)
        ->and($result['totalAmount'])->toBe('1206.00');
});

it('deja totalAmount en cero cuando el vehículo no tiene gastos', function () {
    $vehicle = Vehicle::factory()->create();

    $result = vehicleExpenseService()->getVehicleExpenses(expenseAdmin(), ['vehicleId' => $vehicle->id]);

    expect($result['expenses'])->toHaveCount(0)
        ->and($result['totalAmount'])->toBe('0.00');
});

it('aplica el filtro de categoría solo cuando pertenece al enum', function (?string $category, int $expected) {
    $vehicle = Vehicle::factory()->create();
    $user = expenseAdmin();

    VehicleExpense::factory()->count(2)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $user->id,
        'category' => VehicleExpenseCategory::Tires,
    ]);
    VehicleExpense::factory()->count(3)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $user->id,
        'category' => VehicleExpenseCategory::Brakes,
    ]);

    $result = vehicleExpenseService()->getVehicleExpenses($user, [
        'vehicleId' => $vehicle->id,
        'category' => $category,
    ]);

    expect($result['expenses'])->toHaveCount($expected);
})->with([
    'sin filtro' => [null, 5],
    'llantas' => ['tires', 2],
    'frenos' => ['brakes', 3],
    'fuera del enum' => ['cualquiercosa', 5],
    'cadena vacía' => ['', 5],
]);

it('aplica el filtro de naturaleza solo cuando pertenece al enum', function (?string $nature, int $expected) {
    $vehicle = Vehicle::factory()->create();
    $user = expenseAdmin();

    VehicleExpense::factory()->count(2)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $user->id,
        'nature' => VehicleExpenseNature::Preventive,
    ]);
    VehicleExpense::factory()->count(4)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $user->id,
        'nature' => VehicleExpenseNature::Corrective,
    ]);

    $result = vehicleExpenseService()->getVehicleExpenses($user, [
        'vehicleId' => $vehicle->id,
        'nature' => $nature,
    ]);

    expect($result['expenses'])->toHaveCount($expected);
})->with([
    'sin filtro' => [null, 6],
    'preventivos' => ['preventive', 2],
    'correctivos' => ['corrective', 4],
    'fuera del enum' => ['cualquiercosa', 6],
]);

it('acota por fecha con dateFrom y dateTo inclusive e ignora una fecha inservible', function (?string $from, ?string $to, int $expected) {
    $vehicle = Vehicle::factory()->create();
    $user = expenseAdmin();

    foreach (['2026-01-01', '2026-01-15', '2026-01-31'] as $date) {
        VehicleExpense::factory()->create([
            'vehicle_id' => $vehicle->id,
            'registered_by' => $user->id,
            'expense_date' => $date,
        ]);
    }

    $result = vehicleExpenseService()->getVehicleExpenses($user, [
        'vehicleId' => $vehicle->id,
        'dateFrom' => $from,
        'dateTo' => $to,
    ]);

    expect($result['expenses'])->toHaveCount($expected);
})->with([
    'sin cotas' => [null, null, 3],
    'desde incluye su propio día' => ['2026-01-15', null, 2],
    'hasta incluye su propio día' => [null, '2026-01-15', 2],
    'rango cerrado inclusive' => ['2026-01-01', '2026-01-31', 3],
    'rango que deja fuera los extremos' => ['2026-01-02', '2026-01-30', 1],
    'día inexistente se ignora' => ['2026-02-30', null, 3],
    'mes y día fuera de rango se ignora' => ['2026-13-45', null, 3],
    'otro formato se ignora' => [null, '15/01/2026', 3],
    'cadena vacía se ignora' => ['', '', 3],
]);

it('refleja en el acumulado los filtros aplicados', function () {
    $vehicle = Vehicle::factory()->create();
    $user = expenseAdmin();

    VehicleExpense::factory()->count(2)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $user->id,
        'category' => VehicleExpenseCategory::Tires,
        'amount' => 100.00,
    ]);
    VehicleExpense::factory()->count(3)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $user->id,
        'category' => VehicleExpenseCategory::Brakes,
        'amount' => 999.99,
    ]);

    $result = vehicleExpenseService()->getVehicleExpenses($user, [
        'vehicleId' => $vehicle->id,
        'category' => 'tires',
    ]);

    expect($result['totalAmount'])->toBe('200.00');
});

it('lista los gastos de un vehículo desactivado', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'status' => VehicleStatus::Inactive,
    ]);

    VehicleExpense::factory()->count(2)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    expect(vehicleExpenseService()->getVehicleExpenses($carrier->owner, ['vehicleId' => $vehicle->id])['expenses'])
        ->toHaveCount(2);
});

it('deja leer a un manager los gastos de cualquier empresa', function () {
    $vehicle = Vehicle::factory()->create();

    VehicleExpense::factory()->count(2)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $vehicle->carrier->owner->id,
    ]);

    expect(vehicleExpenseService()->getVehicleExpenses(expenseManager(), ['vehicleId' => $vehicle->id])['expenses'])
        ->toHaveCount(2);
});

it('lanza NotFoundError al listar los gastos de un vehículo inexistente', function () {
    expect(fn () => vehicleExpenseService()->getVehicleExpenses(expenseAdmin(), ['vehicleId' => 99999]))
        ->toThrow(NotFoundError::class, 'El vehículo no existe');
});

it('lanza ForbiddenError cuando un carrier lista los gastos de un vehículo ajeno', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = Vehicle::factory()->create();

    expect(fn () => vehicleExpenseService()->getVehicleExpenses($carrier->owner, ['vehicleId' => $ajeno->id]))
        ->toThrow(ForbiddenError::class, 'No puedes acceder a un vehículo que no pertenece a tu empresa transportista');
});

it('lanza ForbiddenError cuando quien lista no pertenece a ninguna empresa', function () {
    $vehicle = Vehicle::factory()->create();
    $sinEmpresa = User::factory()->create(['role' => UserRole::Carrier]);

    expect(fn () => vehicleExpenseService()->getVehicleExpenses($sinEmpresa, ['vehicleId' => $vehicle->id]))
        ->toThrow(ForbiddenError::class, 'No perteneces a ninguna empresa transportista');
});

it('precarga el usuario de cada gasto con dos consultas', function () {
    $vehicle = Vehicle::factory()->create();

    VehicleExpense::factory()->count(20)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => expenseAdmin()->id,
    ]);

    $admin = expenseAdmin();

    DB::enableQueryLog();

    $result = vehicleExpenseService()->getVehicleExpenses($admin, ['vehicleId' => $vehicle->id]);

    /** Leer el nombre de quien registró es justo lo que dispararía el N+1 sin with('registeredBy'). */
    $names = $result['expenses']->map(fn (VehicleExpense $expense) => $expense->registeredBy->name);

    $queries = DB::getQueryLog();

    DB::disableQueryLog();

    expect($names)->toHaveCount(20)
        ->and(collect($queries)->filter(fn (array $query) => str_contains($query['query'], 'from "users"')))
        ->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| createVehicleExpense()
|--------------------------------------------------------------------------
*/

it('persiste el gasto con el usuario autenticado como registered_by', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = vehicleExpenseService()->createVehicleExpense(expenseServicePayload($vehicle->id), $carrier->owner);

    expect($expense)->toBeInstanceOf(VehicleExpense::class)
        ->and($expense->vehicle_id)->toBe($vehicle->id)
        ->and($expense->registered_by)->toBe($carrier->owner->id)
        ->and($expense->category)->toBe(VehicleExpenseCategory::Tires)
        ->and($expense->nature)->toBe(VehicleExpenseNature::Preventive)
        ->and($expense->amount)->toBe('1250.50')
        ->and($expense->expense_date->format('Y-m-d'))->toBe('2026-08-12')
        ->and($expense->relationLoaded('registeredBy'))->toBeTrue();

    $this->assertDatabaseHas('vehicle_expenses', [
        'id' => $expense->id,
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);
});

it('ignora el registered_by que viaje en el payload', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);
    $otro = expenseAdmin();

    $expense = vehicleExpenseService()->createVehicleExpense(
        expenseServicePayload($vehicle->id, ['registered_by' => $otro->id]),
        $carrier->owner,
    );

    expect($expense->registered_by)->toBe($carrier->owner->id);
});

it('acepta un gasto sobre un vehículo desactivado', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create([
        'carrier_id' => $carrier->id,
        'status' => VehicleStatus::Inactive,
    ]);

    expect(vehicleExpenseService()->createVehicleExpense(expenseServicePayload($vehicle->id), $carrier->owner)->exists)
        ->toBeTrue();
});

it('lanza NotFoundError al registrar un gasto sobre un vehículo inexistente', function () {
    expect(fn () => vehicleExpenseService()->createVehicleExpense(expenseServicePayload(99999), expenseAdmin()))
        ->toThrow(NotFoundError::class, 'El vehículo no existe');
});

it('lanza ForbiddenError cuando un carrier registra un gasto en un vehículo ajeno', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = Vehicle::factory()->create();

    expect(fn () => vehicleExpenseService()->createVehicleExpense(expenseServicePayload($ajeno->id), $carrier->owner))
        ->toThrow(ForbiddenError::class, 'No puedes acceder a un vehículo que no pertenece a tu empresa transportista');

    $this->assertDatabaseCount('vehicle_expenses', 0);
});

/*
|--------------------------------------------------------------------------
| getVehicleExpenseById()
|--------------------------------------------------------------------------
*/

it('devuelve el gasto buscado por id', function () {
    $expense = VehicleExpense::factory()->create();

    expect(vehicleExpenseService()->getVehicleExpenseById(expenseAdmin(), $expense->id))
        ->toBeInstanceOf(VehicleExpense::class)
        ->id->toBe($expense->id);
});

it('lanza NotFoundError al buscar un gasto inexistente', function () {
    expect(fn () => vehicleExpenseService()->getVehicleExpenseById(expenseAdmin(), 99999))
        ->toThrow(NotFoundError::class, 'El gasto no existe');
});

it('lanza ForbiddenError cuando un carrier alcanza un gasto ajeno', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = VehicleExpense::factory()->create();

    expect(fn () => vehicleExpenseService()->getVehicleExpenseById($carrier->owner, $ajeno->id))
        ->toThrow(ForbiddenError::class, 'No puedes acceder a un gasto que no pertenece a tu empresa transportista');
});

it('deja leer a un manager un gasto de cualquier empresa', function () {
    $expense = VehicleExpense::factory()->create();

    expect(vehicleExpenseService()->getVehicleExpenseById(expenseManager(), $expense->id)->id)->toBe($expense->id);
});

/*
|--------------------------------------------------------------------------
| updateVehicleExpense()
|--------------------------------------------------------------------------
*/

it('actualiza solo los campos mandados', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
        'category' => VehicleExpenseCategory::Tires,
        'amount' => 100.00,
        'description' => 'Descripción original',
    ]);

    $updated = vehicleExpenseService()->updateVehicleExpense(['amount' => 250.25], $expense->id, $carrier->owner);

    expect($updated->amount)->toBe('250.25')
        ->and($updated->category)->toBe(VehicleExpenseCategory::Tires)
        ->and($updated->description)->toBe('Descripción original');
});

it('no mueve el gasto de vehículo ni reescribe registered_by al actualizar', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);
    $otro = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    $admin = expenseAdmin();

    $updated = vehicleExpenseService()->updateVehicleExpense([
        'vehicle_id' => $otro->id,
        'registered_by' => $admin->id,
        'description' => 'Intento de mudanza',
    ], $expense->id, $admin);

    expect($updated->vehicle_id)->toBe($vehicle->id)
        ->and($updated->registered_by)->toBe($carrier->owner->id)
        ->and($updated->description)->toBe('Intento de mudanza');

    $this->assertDatabaseHas('vehicle_expenses', [
        'id' => $expense->id,
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);
});

it('no toca nada con un payload vacío', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
        'description' => 'Sin tocar',
    ]);

    $updated = vehicleExpenseService()->updateVehicleExpense([], $expense->id, $carrier->owner);

    expect($updated->description)->toBe('Sin tocar')
        ->and($updated->updated_at->eq($expense->updated_at))->toBeTrue();
});

it('lanza NotFoundError al actualizar un gasto inexistente', function () {
    expect(fn () => vehicleExpenseService()->updateVehicleExpense(['description' => 'Fantasma'], 99999, expenseAdmin()))
        ->toThrow(NotFoundError::class, 'El gasto no existe');
});

it('lanza ForbiddenError cuando un carrier actualiza un gasto ajeno', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = VehicleExpense::factory()->create(['description' => 'Intacta']);

    expect(fn () => vehicleExpenseService()->updateVehicleExpense(['description' => 'Secuestrada'], $ajeno->id, $carrier->owner))
        ->toThrow(ForbiddenError::class, 'No puedes acceder a un gasto que no pertenece a tu empresa transportista');

    expect($ajeno->fresh()->description)->toBe('Intacta');
});

/*
|--------------------------------------------------------------------------
| deleteVehicleExpense()
|--------------------------------------------------------------------------
*/

it('borra de verdad el gasto y devuelve el modelo eliminado', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    $deleted = vehicleExpenseService()->deleteVehicleExpense($expense->id, $carrier->owner);

    expect($deleted)->toBeInstanceOf(VehicleExpense::class)
        ->and($deleted->id)->toBe($expense->id);

    $this->assertDatabaseMissing('vehicle_expenses', ['id' => $expense->id]);
});

it('lanza NotFoundError en el segundo borrado del mismo gasto', function () {
    $expense = VehicleExpense::factory()->create();
    $admin = expenseAdmin();

    vehicleExpenseService()->deleteVehicleExpense($expense->id, $admin);

    expect(fn () => vehicleExpenseService()->deleteVehicleExpense($expense->id, $admin))
        ->toThrow(NotFoundError::class, 'El gasto no existe');
});

it('lanza ForbiddenError cuando un carrier borra un gasto ajeno', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = VehicleExpense::factory()->create();

    expect(fn () => vehicleExpenseService()->deleteVehicleExpense($ajeno->id, $carrier->owner))
        ->toThrow(ForbiddenError::class, 'No puedes acceder a un gasto que no pertenece a tu empresa transportista');

    $this->assertDatabaseHas('vehicle_expenses', ['id' => $ajeno->id]);
});

/*
|--------------------------------------------------------------------------
| Factura
|--------------------------------------------------------------------------
*/

it('guarda la key bajo invoices/ y marca el gasto como facturado', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = vehicleExpenseService()->createVehicleExpense(expenseServicePayload($vehicle->id, [
        'is_invoiced' => true,
        'invoice' => UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf'),
    ]), $carrier->owner);

    expect($expense->is_invoiced)->toBeTrue()
        ->and($expense->invoice)->toStartWith('invoices/')
        ->and($expense->invoice)->toEndWith('.pdf');

    Storage::assertExists($expense->invoice);
});

it('descarta el archivo sin subirlo cuando is_invoiced es falso', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = vehicleExpenseService()->createVehicleExpense(expenseServicePayload($vehicle->id, [
        'is_invoiced' => false,
        'invoice' => UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf'),
    ]), $carrier->owner);

    expect($expense->is_invoiced)->toBeFalse()
        ->and($expense->invoice)->toBeNull()
        ->and(Storage::allFiles())->toBe([]);
});

it('no sube ningún archivo cuando el ámbito deniega el vehículo', function () {
    $carrier = Carrier::factory()->create();
    $ajeno = Vehicle::factory()->create();

    expect(fn () => vehicleExpenseService()->createVehicleExpense(expenseServicePayload($ajeno->id, [
        'is_invoiced' => true,
        'invoice' => UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf'),
    ]), $carrier->owner))
        ->toThrow(ForbiddenError::class, 'No puedes acceder a un vehículo que no pertenece a tu empresa transportista');

    expect(Storage::allFiles())->toBe([]);
});

it('ignora is_invoiced e invoice en la actualización', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
        'is_invoiced' => false,
    ]);

    $updated = vehicleExpenseService()->updateVehicleExpense([
        'is_invoiced' => true,
        'invoice' => UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf'),
        'description' => 'Descripción corregida',
    ], $expense->id, $carrier->owner);

    expect($updated->is_invoiced)->toBeFalse()
        ->and($updated->invoice)->toBeNull()
        ->and($updated->description)->toBe('Descripción corregida')
        ->and(Storage::allFiles())->toBe([]);
});

it('borra el objeto del bucket después de borrar la fila', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = vehicleExpenseService()->createVehicleExpense(expenseServicePayload($vehicle->id, [
        'is_invoiced' => true,
        'invoice' => UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf'),
    ]), $carrier->owner);

    $key = $expense->invoice;

    vehicleExpenseService()->deleteVehicleExpense($expense->id, $carrier->owner);

    Storage::assertMissing($key);

    $this->assertDatabaseMissing('vehicle_expenses', ['id' => $expense->id]);
});

it('no rompe el borrado cuando la limpieza del archivo falla', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    /** La key apunta a un objeto que nunca se subió, así que delete() devuelve false sin lanzar. */
    $expense = VehicleExpense::factory()->invoiced()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    $deleted = vehicleExpenseService()->deleteVehicleExpense($expense->id, $carrier->owner);

    expect($deleted->id)->toBe($expense->id);

    $this->assertDatabaseMissing('vehicle_expenses', ['id' => $expense->id]);
});

it('filtra el listado por isInvoiced y acumula solo lo filtrado', function () {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    VehicleExpense::factory()->invoiced()->count(2)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
        'amount' => 100,
    ]);

    VehicleExpense::factory()->count(3)->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
        'amount' => 10,
    ]);

    $facturados = vehicleExpenseService()->getVehicleExpenses($carrier->owner, [
        'vehicleId' => $vehicle->id,
        'isInvoiced' => 'true',
    ]);

    expect($facturados['expenses'])->toHaveCount(2)
        ->and($facturados['totalAmount'])->toBe('200.00');

    $noFacturados = vehicleExpenseService()->getVehicleExpenses($carrier->owner, [
        'vehicleId' => $vehicle->id,
        'isInvoiced' => 'false',
    ]);

    expect($noFacturados['expenses'])->toHaveCount(3)
        ->and($noFacturados['totalAmount'])->toBe('30.00');
});

it('ignora un isInvoiced ilegible y devuelve el listado completo', function (?string $value) {
    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    VehicleExpense::factory()->invoiced()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    VehicleExpense::factory()->create([
        'vehicle_id' => $vehicle->id,
        'registered_by' => $carrier->owner->id,
    ]);

    $result = vehicleExpenseService()->getVehicleExpenses($carrier->owner, [
        'vehicleId' => $vehicle->id,
        'isInvoiced' => $value,
    ]);

    expect($result['expenses'])->toHaveCount(2);
})->with(['quizá', 'vacío' => '', '2', 'nulo' => null]);

it('sube la factura a través de cualquier implementación del contrato de almacenamiento', function () {
    $doble = new InMemoryFileStorageService;

    app()->instance(FileStorageServiceInterface::class, $doble);

    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    $expense = app(VehicleExpenseService::class)->createVehicleExpense(expenseServicePayload($vehicle->id, [
        'is_invoiced' => true,
        'invoice' => UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf'),
    ]), $carrier->owner);

    expect($doble->keys())->toBe([$expense->invoice])
        ->and($expense->invoice)->toStartWith('invoices/')
        ->and(Storage::allFiles())->toBe([]);
});

it('propaga como BadRequestError el fallo del almacenamiento al subir la factura', function () {
    app()->instance(FileStorageServiceInterface::class, new InMemoryFileStorageService(failing: true));

    $carrier = Carrier::factory()->create();
    $vehicle = Vehicle::factory()->create(['carrier_id' => $carrier->id]);

    expect(fn () => app(VehicleExpenseService::class)->createVehicleExpense(expenseServicePayload($vehicle->id, [
        'is_invoiced' => true,
        'invoice' => UploadedFile::fake()->create('factura.pdf', 40, 'application/pdf'),
    ]), $carrier->owner))
        ->toThrow(BadRequestError::class, 'No se pudo almacenar el archivo');

    expect(VehicleExpense::query()->count())->toBe(0);
});
