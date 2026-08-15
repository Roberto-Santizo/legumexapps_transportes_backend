<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Models\Carrier;
use App\Models\CarrierPilot;
use App\Models\CarrierPilotSalaryHistory;
use App\Models\User;
use App\Services\Pilot\PilotService;
use Illuminate\Database\QueryException;

function pilotService(): PilotService
{
    return new PilotService;
}

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
