<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Carrier\CarrierServiceInterface;
use App\Models\Carrier;
use App\Models\CarrierPilot;
use App\Models\User;
use App\Services\Carrier\CarrierService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;

function carrierService(): CarrierServiceInterface
{
    return app(CarrierServiceInterface::class);
}

function pilotUser(): User
{
    return User::factory()->create(['role' => UserRole::Pilot]);
}

it('resuelve la implementación registrada en el provider', function () {
    expect(carrierService())->toBeInstanceOf(CarrierService::class);
});

/*
|--------------------------------------------------------------------------
| Base de datos y modelos
|--------------------------------------------------------------------------
*/

it('crea las tablas carriers y carrier_pilots', function () {
    expect(Schema::hasTable('carriers'))->toBeTrue()
        ->and(Schema::hasTable('carrier_pilots'))->toBeTrue();
});

it('no añade ninguna columna nueva a la tabla users', function () {
    expect(Schema::getColumnListing('users'))->toEqualCanonicalizing([
        'id',
        'name',
        'email',
        'role',
        'email_verified_at',
        'password',
        'remember_token',
        'created_at',
        'updated_at',
    ]);
});

it('impide dos empresas con el mismo dueño', function () {
    $carrier = Carrier::factory()->create();

    expect(fn () => Carrier::factory()->create(['user_id' => $carrier->user_id]))
        ->toThrow(QueryException::class);
});

it('impide que un piloto pertenezca a dos empresas', function () {
    $primera = Carrier::factory()->create();
    $segunda = Carrier::factory()->create();
    $pilot = pilotUser();

    CarrierPilot::create(['carrier_id' => $primera->id, 'user_id' => $pilot->id]);

    expect(fn () => CarrierPilot::create(['carrier_id' => $segunda->id, 'user_id' => $pilot->id]))
        ->toThrow(QueryException::class);
});

it('expone el dueño y los pilotos de una empresa', function () {
    $carrier = Carrier::factory()->create();
    $pilots = User::factory()->count(2)->create(['role' => UserRole::Pilot]);

    $carrier->pilots()->attach($pilots->pluck('id'));

    expect($carrier->owner)->toBeInstanceOf(User::class)
        ->and($carrier->owner->role)->toBe(UserRole::Carrier)
        ->and($carrier->fresh()->pilots)->toHaveCount(2)
        ->and($carrier->fresh()->pilots->first())->toBeInstanceOf(User::class);
});

it('resuelve la empresa del dueño con currentCarrier', function () {
    $carrier = Carrier::factory()->create();

    expect($carrier->owner->currentCarrier()?->id)->toBe($carrier->id);
});

it('resuelve la empresa vinculada de un piloto con currentCarrier', function () {
    $carrier = Carrier::factory()->create();
    $pilot = pilotUser();

    CarrierPilot::create(['carrier_id' => $carrier->id, 'user_id' => $pilot->id]);

    expect($pilot->fresh()->currentCarrier()?->id)->toBe($carrier->id);
});

it('devuelve null en currentCarrier cuando el usuario no tiene empresa', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);

    expect($user->currentCarrier())->toBeNull();
})->with([
    'carrier sin empresa' => UserRole::Carrier,
    'piloto sin vincular' => UserRole::Pilot,
    'administrador' => UserRole::Administrator,
    'manager' => UserRole::Manager,
]);

/*
|--------------------------------------------------------------------------
| getCarriers()
|--------------------------------------------------------------------------
*/

it('devuelve una colección con todas las empresas sin limit', function () {
    Carrier::factory()->count(12)->create();

    $carriers = carrierService()->getCarriers(null);

    expect($carriers)->toBeInstanceOf(Collection::class)
        ->and($carriers)->toHaveCount(12);
});

it('devuelve una colección con todas las empresas cuando limit no es numérico', function () {
    Carrier::factory()->count(12)->create();

    $carriers = carrierService()->getCarriers('abc');

    expect($carriers)->toBeInstanceOf(Collection::class)
        ->and($carriers)->toHaveCount(12);
});

it('devuelve un paginador de empresas cuando limit es numérico', function () {
    Carrier::factory()->count(12)->create();

    $carriers = carrierService()->getCarriers('10');

    expect($carriers)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($carriers->perPage())->toBe(10)
        ->and($carriers->total())->toBe(12)
        ->and($carriers->lastPage())->toBe(2);
});

it('acota el tamaño de página de empresas al rango permitido', function (string $limit, int $expected) {
    Carrier::factory()->count(2)->create();

    expect(carrierService()->getCarriers($limit)->perPage())->toBe($expected);
})->with([
    'por debajo del mínimo' => ['1', 10],
    'dentro del rango' => ['25', 25],
    'por encima del máximo' => ['500', 100],
]);

/*
|--------------------------------------------------------------------------
| getCarrierById()
|--------------------------------------------------------------------------
*/

it('devuelve la empresa buscada por id', function () {
    $carrier = Carrier::factory()->create();

    expect(carrierService()->getCarrierById($carrier->id))
        ->toBeInstanceOf(Carrier::class)
        ->id->toBe($carrier->id);
});

it('lanza NotFoundError al buscar una empresa inexistente', function () {
    expect(fn () => carrierService()->getCarrierById(99999))
        ->toThrow(NotFoundError::class, 'El transportista no existe');
});

/*
|--------------------------------------------------------------------------
| getMyCarrier()
|--------------------------------------------------------------------------
*/

it('devuelve la empresa del usuario dueño', function () {
    $carrier = Carrier::factory()->create();

    expect(carrierService()->getMyCarrier($carrier->owner)->id)->toBe($carrier->id);
});

it('lanza NotFoundError cuando el carrier todavía no registró su empresa', function () {
    $user = User::factory()->create(['role' => UserRole::Carrier]);

    expect(fn () => carrierService()->getMyCarrier($user))
        ->toThrow(NotFoundError::class, 'Todavía no has registrado tu empresa transportista');
});

/*
|--------------------------------------------------------------------------
| getMyPilots()
|--------------------------------------------------------------------------
*/

it('devuelve una colección con todos los pilotos de la empresa sin limit', function () {
    $carrier = Carrier::factory()->create();
    $carrier->pilots()->attach(User::factory()->count(3)->create(['role' => UserRole::Pilot])->pluck('id'));

    $pilots = carrierService()->getMyPilots($carrier->owner, null);

    expect($pilots)->toBeInstanceOf(Collection::class)
        ->and($pilots)->toHaveCount(3);
});

it('devuelve un paginador de pilotos cuando limit es numérico', function () {
    $carrier = Carrier::factory()->create();
    $carrier->pilots()->attach(User::factory()->count(12)->create(['role' => UserRole::Pilot])->pluck('id'));

    $pilots = carrierService()->getMyPilots($carrier->owner, '10');

    expect($pilots)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($pilots->total())->toBe(12)
        ->and($pilots->lastPage())->toBe(2);
});

it('acota el tamaño de página de pilotos al rango permitido', function (string $limit, int $expected) {
    $carrier = Carrier::factory()->create();

    expect(carrierService()->getMyPilots($carrier->owner, $limit)->perPage())->toBe($expected);
})->with([
    'por debajo del mínimo' => ['1', 10],
    'dentro del rango' => ['25', 25],
    'por encima del máximo' => ['500', 100],
]);

it('no devuelve los pilotos de otra empresa', function () {
    $carrier = Carrier::factory()->create();
    $otra = Carrier::factory()->create();

    $propio = pilotUser();
    $ajeno = pilotUser();

    $carrier->pilots()->attach($propio->id);
    $otra->pilots()->attach($ajeno->id);

    expect(carrierService()->getMyPilots($carrier->owner, null)->pluck('id')->all())->toBe([$propio->id]);
});

/*
|--------------------------------------------------------------------------
| createCarrier()
|--------------------------------------------------------------------------
*/

it('persiste la empresa con un código único, activa y con la key de la imagen', function () {
    $user = User::factory()->create(['role' => UserRole::Carrier]);

    $carrier = carrierService()->createCarrier([
        'name' => 'Transportes del Norte',
        'image' => UploadedFile::fake()->image('logo.png'),
    ], $user);

    expect($carrier)->toBeInstanceOf(Carrier::class)
        ->and($carrier->active)->toBeTrue()
        ->and($carrier->code)->toMatch('/^[A-Z0-9]{6}$/')
        ->and($carrier->image)->toStartWith('carriers/')->toEndWith('.png');

    $this->assertDatabaseHas('carriers', [
        'user_id' => $user->id,
        'name' => 'Transportes del Norte',
        'code' => $carrier->code,
    ]);
});

it('lanza BadRequestError cuando el usuario ya tiene empresa', function () {
    $carrier = Carrier::factory()->create();

    expect(fn () => carrierService()->createCarrier([
        'name' => 'Segunda empresa',
        'image' => UploadedFile::fake()->image('logo.png'),
    ], $carrier->owner))->toThrow(BadRequestError::class, 'Ya tienes una empresa transportista registrada');

    $this->assertDatabaseCount('carriers', 1);
});

/*
|--------------------------------------------------------------------------
| updateCarrier()
|--------------------------------------------------------------------------
*/

it('actualiza solo los campos enviados', function () {
    $carrier = Carrier::factory()->create(['name' => 'Nombre antiguo', 'image' => 'anterior.png']);

    $updated = carrierService()->updateCarrier(['name' => 'Nombre nuevo'], $carrier->id, $carrier->owner);

    expect($updated->name)->toBe('Nombre nuevo')
        ->and($updated->image)->toBe('anterior.png')
        ->and($updated->active)->toBeTrue();
});

it('actualiza nombre, imagen y estado del dueño', function () {
    $carrier = Carrier::factory()->create();

    $updated = carrierService()->updateCarrier([
        'name' => 'Transportes del Sur',
        'image' => UploadedFile::fake()->image('nuevo.jpg'),
        'active' => false,
    ], $carrier->id, $carrier->owner);

    expect($updated->name)->toBe('Transportes del Sur')
        ->and($updated->image)->toStartWith('carriers/')->toEndWith('.jpg')
        ->and($updated->active)->toBeFalse();

    $this->assertDatabaseHas('carriers', ['id' => $carrier->id, 'name' => 'Transportes del Sur', 'active' => false]);
});

it('lanza ForbiddenError cuando un carrier actualiza una empresa ajena', function () {
    $propia = Carrier::factory()->create();
    $ajena = Carrier::factory()->create();

    expect(fn () => carrierService()->updateCarrier(['name' => 'Secuestrada'], $ajena->id, $propia->owner))
        ->toThrow(ForbiddenError::class, 'No puedes actualizar una empresa transportista que no te pertenece');
});

it('permite a un administrador actualizar una empresa ajena', function () {
    $carrier = Carrier::factory()->create();
    $admin = User::factory()->create(['role' => UserRole::Administrator]);

    expect(carrierService()->updateCarrier(['name' => 'Nombre corregido'], $carrier->id, $admin)->name)
        ->toBe('Nombre corregido');
});

it('lanza NotFoundError al actualizar una empresa inexistente', function () {
    $admin = User::factory()->create(['role' => UserRole::Administrator]);

    expect(fn () => carrierService()->updateCarrier(['name' => 'Fantasma'], 99999, $admin))
        ->toThrow(NotFoundError::class, 'El transportista no existe');
});

/*
|--------------------------------------------------------------------------
| deleteCarrier()
|--------------------------------------------------------------------------
*/

it('no borra la empresa al eliminarla', function () {
    $carrier = Carrier::factory()->create();

    carrierService()->deleteCarrier($carrier->id);

    $this->assertDatabaseHas('carriers', ['id' => $carrier->id]);
});

it('lanza NotFoundError al eliminar una empresa inexistente', function () {
    expect(fn () => carrierService()->deleteCarrier(99999))
        ->toThrow(NotFoundError::class, 'El transportista no existe');
});

/*
|--------------------------------------------------------------------------
| joinCarrier()
|--------------------------------------------------------------------------
*/

it('vincula al piloto normalizando el código a mayúsculas', function () {
    $carrier = Carrier::factory()->create(['code' => 'A7K2QX']);
    $pilot = pilotUser();

    carrierService()->joinCarrier(['code' => 'a7k2qx'], $pilot);

    $this->assertDatabaseHas('carrier_pilots', ['carrier_id' => $carrier->id, 'user_id' => $pilot->id]);
});

it('lanza NotFoundError cuando el código no existe', function () {
    Carrier::factory()->create(['code' => 'A7K2QX']);

    expect(fn () => carrierService()->joinCarrier(['code' => 'ZZZZZZ'], pilotUser()))
        ->toThrow(NotFoundError::class, 'El código no pertenece a ninguna empresa transportista');

    $this->assertDatabaseCount('carrier_pilots', 0);
});

it('lanza BadRequestError cuando el piloto ya pertenece a una empresa', function () {
    $original = Carrier::factory()->create(['code' => 'A7K2QX']);
    Carrier::factory()->create(['code' => 'B8L3RY']);

    $pilot = pilotUser();
    CarrierPilot::create(['carrier_id' => $original->id, 'user_id' => $pilot->id]);

    expect(fn () => carrierService()->joinCarrier(['code' => 'B8L3RY'], $pilot->fresh()))
        ->toThrow(BadRequestError::class, 'Ya perteneces a una empresa transportista');

    $this->assertDatabaseCount('carrier_pilots', 1);
});
