<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Pilot\PilotServiceInterface;
use App\Models\Carrier;
use App\Models\CarrierPilot;
use App\Models\CarrierPilotSalaryHistory;
use App\Models\User;
use App\Services\Pilot\PilotService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

function pilotService(): PilotServiceInterface
{
    return app(PilotServiceInterface::class);
}

it('resuelve la implementación de pilotos registrada en el provider', function () {
    expect(pilotService())->toBeInstanceOf(PilotService::class);
});

function pilotServiceAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * Vincula un piloto nuevo a la empresa dada, opcionalmente con salario ya asignado.
 *
 * CarrierPilot no tiene factory: la pivote se crea a mano, como en CarrierServiceTest.
 */
function linkPilot(Carrier $carrier, int|float|string|null $salary = null): CarrierPilot
{
    return CarrierPilot::create([
        'carrier_id' => $carrier->id,
        'user_id' => User::factory()->create(['role' => UserRole::Pilot])->id,
        'salary' => $salary,
    ]);
}

/*
|--------------------------------------------------------------------------
| Base de datos y modelos
|--------------------------------------------------------------------------
*/

it('crea la tabla del historial de salarios', function () {
    expect(Schema::hasTable('carrier_pilot_salary_histories'))->toBeTrue()
        ->and(Schema::hasColumn('carrier_pilots', 'salary'))->toBeTrue();
});

it('persiste el salario con dos decimales al crear la pivote', function () {
    $pilot = linkPilot(Carrier::factory()->create(), 4500);

    expect($pilot->fresh()->salary)->toBe('4500.00');
});

it('borra el historial al borrar la fila pivote', function () {
    $pilot = linkPilot(Carrier::factory()->create());

    CarrierPilotSalaryHistory::factory()->count(3)->create(['carrier_pilot_id' => $pilot->id]);

    $pilot->delete();

    expect(CarrierPilotSalaryHistory::query()->where('carrier_pilot_id', $pilot->id)->count())->toBe(0);
});

/** La FK a users no cascadea a propósito: borrar al autor no puede borrar el rastro. */
it('impide borrar al usuario que figura como autor de un cambio', function () {
    $pilot = linkPilot(Carrier::factory()->create());
    $admin = pilotServiceAdmin();

    pilotService()->updateSalary($pilot->user_id, ['salary' => 4500], $admin);

    expect(fn () => $admin->delete())->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| Asignación de salario
|--------------------------------------------------------------------------
*/

it('deja el salario en null hasta que alguien lo asigna', function () {
    expect(linkPilot(Carrier::factory()->create())->salary)->toBeNull();
});

it('registra la primera asignación con previous_salary null', function () {
    $pilot = linkPilot(Carrier::factory()->create());
    $admin = pilotServiceAdmin();

    $updated = pilotService()->updateSalary($pilot->user_id, ['salary' => 4500], $admin);

    $histories = CarrierPilotSalaryHistory::query()->where('carrier_pilot_id', $pilot->id)->get();

    expect($updated->salary)->toBe('4500.00')
        ->and($histories)->toHaveCount(1)
        ->and($histories->first()->previous_salary)->toBeNull()
        ->and($histories->first()->new_salary)->toBe('4500.00')
        ->and($histories->first()->changed_by)->toBe($admin->id);
});

it('encadena la segunda asignación con el salario anterior', function () {
    $pilot = linkPilot(Carrier::factory()->create(), 4500);
    $admin = pilotServiceAdmin();

    pilotService()->updateSalary($pilot->user_id, ['salary' => 5200], $admin);

    $history = CarrierPilotSalaryHistory::query()->where('carrier_pilot_id', $pilot->id)->latest('id')->first();

    expect($pilot->fresh()->salary)->toBe('5200.00')
        ->and($history->previous_salary)->toBe('4500.00')
        ->and($history->new_salary)->toBe('5200.00');
});

it('registra una bajada de salario igual que una subida', function () {
    $pilot = linkPilot(Carrier::factory()->create(), 4500);

    pilotService()->updateSalary($pilot->user_id, ['salary' => 3800], pilotServiceAdmin());

    $history = CarrierPilotSalaryHistory::query()->where('carrier_pilot_id', $pilot->id)->latest('id')->first();

    expect($pilot->fresh()->salary)->toBe('3800.00')
        ->and($history->previous_salary)->toBe('4500.00')
        ->and($history->new_salary)->toBe('3800.00');
});

it('deja dos cambios encadenados tras dos asignaciones distintas', function () {
    $pilot = linkPilot(Carrier::factory()->create());
    $admin = pilotServiceAdmin();

    pilotService()->updateSalary($pilot->user_id, ['salary' => 4500], $admin);
    pilotService()->updateSalary($pilot->user_id, ['salary' => 5200], $admin);

    $histories = CarrierPilotSalaryHistory::query()
        ->where('carrier_pilot_id', $pilot->id)
        ->orderBy('id')
        ->get();

    expect($histories)->toHaveCount(2)
        ->and($histories[0]->previous_salary)->toBeNull()
        ->and($histories[0]->new_salary)->toBe('4500.00')
        ->and($histories[1]->previous_salary)->toBe('4500.00')
        ->and($histories[1]->new_salary)->toBe('5200.00');
});

it('rechaza el mismo salario que el piloto ya tiene y no escribe en la bitácora', function () {
    $pilot = linkPilot(Carrier::factory()->create(), 4500);
    $admin = pilotServiceAdmin();

    pilotService()->updateSalary($pilot->user_id, ['salary' => 5200], $admin);

    expect(fn () => pilotService()->updateSalary($pilot->user_id, ['salary' => 5200], $admin))
        ->toThrow(BadRequestError::class, 'El salario indicado es el mismo que el piloto ya tiene registrado')
        ->and(CarrierPilotSalaryHistory::query()->where('carrier_pilot_id', $pilot->id)->count())->toBe(1);
});

/** El mismo truco de fuelMinValue() de SPEC 09: la comparación es sobre el valor a dos decimales. */
it('trata 4500, 4500.00 y 4500.004 como el mismo salario', function (int|float|string $salary) {
    $pilot = linkPilot(Carrier::factory()->create(), 4500);

    expect(fn () => pilotService()->updateSalary($pilot->user_id, ['salary' => $salary], pilotServiceAdmin()))
        ->toThrow(BadRequestError::class);
})->with([4500, 4500.00, '4500.00', 4500.004]);

it('revierte el salario si falla la escritura de la bitácora', function () {
    $pilot = linkPilot(Carrier::factory()->create(), 4500);
    $admin = pilotServiceAdmin();

    /** Un changed_by inexistente hace fallar la FK de la bitácora dentro de la transacción. */
    $admin->id = 999999;

    expect(fn () => pilotService()->updateSalary($pilot->user_id, ['salary' => 5200], $admin))->toThrow(QueryException::class)
        ->and($pilot->fresh()->salary)->toBe('4500.00')
        ->and(CarrierPilotSalaryHistory::query()->where('carrier_pilot_id', $pilot->id)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Guarda por id
|--------------------------------------------------------------------------
*/

it('responde 404 con un user_id que no existe', function () {
    expect(fn () => pilotService()->updateSalary(999999, ['salary' => 4500], pilotServiceAdmin()))
        ->toThrow(NotFoundError::class, 'El piloto no existe o no está vinculado a ninguna empresa transportista');
});

it('responde 404 con un usuario que existe pero no es piloto de nadie', function () {
    $user = User::factory()->create(['role' => UserRole::Pilot]);

    expect(fn () => pilotService()->updateSalary($user->id, ['salary' => 4500], pilotServiceAdmin()))
        ->toThrow(NotFoundError::class, 'El piloto no existe o no está vinculado a ninguna empresa transportista');
});

it('impide a un transportista tocar el salario de un piloto de otra empresa', function () {
    $pilot = linkPilot(Carrier::factory()->create());
    $otherOwner = Carrier::factory()->create()->owner;

    expect(fn () => pilotService()->updateSalary($pilot->user_id, ['salary' => 4500], $otherOwner))
        ->toThrow(ForbiddenError::class);
});

it('permite a un transportista asignar el salario de un piloto suyo', function () {
    $carrier = Carrier::factory()->create();
    $pilot = linkPilot($carrier);

    $updated = pilotService()->updateSalary($pilot->user_id, ['salary' => 4500], $carrier->owner);

    expect($updated->salary)->toBe('4500.00');
});

/*
|--------------------------------------------------------------------------
| Bitácora
|--------------------------------------------------------------------------
*/

it('devuelve los cambios del más reciente al más antiguo', function () {
    $pilot = linkPilot(Carrier::factory()->create());
    $admin = pilotServiceAdmin();

    foreach ([4500, 5200, 4800] as $salary) {
        pilotService()->updateSalary($pilot->user_id, ['salary' => $salary], $admin);
    }

    $history = pilotService()->getSalaryHistory($pilot->user_id, $admin, null);

    expect($history)->toBeInstanceOf(Collection::class)
        ->and($history)->toHaveCount(3)
        ->and($history->pluck('new_salary')->all())->toBe(['4800.00', '5200.00', '4500.00'])
        /** La más antigua es la única con previous_salary null. */
        ->and($history->last()->previous_salary)->toBeNull();
});

it('devuelve una colección vacía para un piloto sin cambios', function () {
    $pilot = linkPilot(Carrier::factory()->create());

    expect(pilotService()->getSalaryHistory($pilot->user_id, pilotServiceAdmin(), null))->toHaveCount(0);
});

it('resuelve el autor de cada cambio sin consultarlo aparte', function () {
    $pilot = linkPilot(Carrier::factory()->create());
    $admin = pilotServiceAdmin();

    pilotService()->updateSalary($pilot->user_id, ['salary' => 4500], $admin);

    $history = pilotService()->getSalaryHistory($pilot->user_id, $admin, null);

    expect($history->first()->relationLoaded('changedBy'))->toBeTrue()
        ->and($history->first()->changedBy->name)->toBe($admin->name);
});

it('pagina el historial con la misma regla acotada a [10, 100]', function (?string $limit, int $expected) {
    $pilot = linkPilot(Carrier::factory()->create());

    CarrierPilotSalaryHistory::factory()->count(12)->create([
        'carrier_pilot_id' => $pilot->id,
        'changed_by' => pilotServiceAdmin()->id,
    ]);

    $history = pilotService()->getSalaryHistory($pilot->user_id, pilotServiceAdmin(), $limit);

    expect($history)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($history->perPage())->toBe($expected)
        ->and($history->total())->toBe(12);
})->with([
    ['10', 10],
    ['1', 10],
    ['500', 100],
]);

it('no pagina el historial sin limit numérico', function (?string $limit) {
    $pilot = linkPilot(Carrier::factory()->create());

    CarrierPilotSalaryHistory::factory()->count(12)->create([
        'carrier_pilot_id' => $pilot->id,
        'changed_by' => pilotServiceAdmin()->id,
    ]);

    expect(pilotService()->getSalaryHistory($pilot->user_id, pilotServiceAdmin(), $limit))
        ->toBeInstanceOf(Collection::class)
        ->toHaveCount(12);
})->with([null, 'abc']);

it('impide a un transportista leer el historial de un piloto de otra empresa', function () {
    $pilot = linkPilot(Carrier::factory()->create());
    $otherOwner = Carrier::factory()->create()->owner;

    expect(fn () => pilotService()->getSalaryHistory($pilot->user_id, $otherOwner, null))
        ->toThrow(ForbiddenError::class);
});

it('deja a un transportista leer el historial de un piloto suyo', function () {
    $carrier = Carrier::factory()->create();
    $pilot = linkPilot($carrier);

    pilotService()->updateSalary($pilot->user_id, ['salary' => 4500], $carrier->owner);

    expect(pilotService()->getSalaryHistory($pilot->user_id, $carrier->owner, null))->toHaveCount(1);
});

it('responde 404 al pedir el historial de un user_id inexistente', function () {
    expect(fn () => pilotService()->getSalaryHistory(999999, pilotServiceAdmin(), null))
        ->toThrow(NotFoundError::class, 'El piloto no existe o no está vinculado a ninguna empresa transportista');
});
