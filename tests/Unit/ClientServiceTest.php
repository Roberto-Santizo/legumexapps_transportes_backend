<?php

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\Client\ClientServiceInterface;
use App\Models\Client;
use App\Models\User;
use App\Services\Client\ClientService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;

function clientService(): ClientServiceInterface
{
    return app(ClientServiceInterface::class);
}

function clientServiceAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * The payload the service expects, straight from the validated request.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function clientServiceData(array $overrides = []): array
{
    return array_merge([
        'code' => 'cli-001',
        'name' => 'agroexportadora del sur',
    ], $overrides);
}

it('resuelve la implementación de clientes registrada en el provider', function () {
    expect(clientService())->toBeInstanceOf(ClientService::class);
});

/*
|--------------------------------------------------------------------------
| Base de datos y modelo
|--------------------------------------------------------------------------
*/

it('crea la tabla clients con sus columnas, incluida deleted_at', function () {
    expect(Schema::hasTable('clients'))->toBeTrue()
        ->and(Schema::getColumnListing('clients'))->toEqualCanonicalizing([
            'id',
            'code',
            'name',
            'registered_by',
            'created_at',
            'updated_at',
            'deleted_at',
        ]);
});

it('normaliza el nombre trimando, colapsando espacios y pasando a mayúsculas', function (string $entrada, string $esperado) {
    expect(Client::normalizeName($entrada))->toBe($esperado);
})->with([
    'con espacios de sobra' => ['  agro   del sur ', 'AGRO DEL SUR'],
    'ya normalizado' => ['AGRO DEL SUR', 'AGRO DEL SUR'],
    'con saltos de línea' => ["agro\n\tdel sur", 'AGRO DEL SUR'],
    'vacío' => ['   ', ''],
]);

it('normaliza el código trimando y pasando a mayúsculas, sin colapsar los espacios internos', function (string $entrada, string $esperado) {
    expect(Client::normalizeCode($entrada))->toBe($esperado);
})->with([
    'con espacios alrededor' => [' cli-001 ', 'CLI-001'],
    'ya normalizado' => ['CLI-001', 'CLI-001'],
    /** El espacio interior sobrevive a propósito: el FormRequest lo rechaza después con un 422. */
    'con un espacio en medio' => ['cli 001', 'CLI 001'],
    'vacío' => ['   ', ''],
]);

/*
|--------------------------------------------------------------------------
| create()
|--------------------------------------------------------------------------
*/

it('persiste el cliente normalizando el código y el nombre', function () {
    $admin = clientServiceAdmin();

    $client = clientService()->create($admin, clientServiceData(['code' => ' cli-001 ', 'name' => '  agro   del sur ']));

    expect($client->code)->toBe('CLI-001')
        ->and($client->name)->toBe('AGRO DEL SUR')
        ->and($client->deleted_at)->toBeNull()
        ->and(Client::query()->whereKey($client->id)->exists())->toBeTrue();
});

it('registra al usuario recibido como responsable del alta', function () {
    $admin = clientServiceAdmin();

    $client = clientService()->create($admin, clientServiceData());

    expect($client->registered_by)->toBe($admin->id)
        ->and($client->registeredBy->name)->toBe($admin->name);
});

it('carga la relación del registrador en el alta', function () {
    expect(clientService()->create(clientServiceAdmin(), clientServiceData())->relationLoaded('registeredBy'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Guardas privadas: código y nombre
|--------------------------------------------------------------------------
*/

it('rechaza un código que ya existe aunque llegue en otra caja', function () {
    $admin = clientServiceAdmin();
    clientService()->create($admin, clientServiceData(['code' => 'CLI-001']));

    clientService()->create($admin, clientServiceData(['code' => 'cli-001', 'name' => 'otro cliente']));
})->throws(BadRequestError::class, 'Ya existe un cliente con ese código, que puede haber sido eliminado');

it('rechaza un nombre que ya existe aunque llegue con espacios de sobra', function () {
    $admin = clientServiceAdmin();
    clientService()->create($admin, clientServiceData(['name' => 'AGRO DEL SUR']));

    clientService()->create($admin, clientServiceData(['code' => 'cli-002', 'name' => '  agro   del   sur ']));
})->throws(BadRequestError::class, 'Ya existe un cliente con ese nombre, que puede haber sido eliminado');

it('sigue viendo el código y el nombre de un cliente borrado al comprobar la disponibilidad', function (array $overrides, string $mensaje) {
    Client::factory()->trashed()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR']);

    expect(fn () => clientService()->create(clientServiceAdmin(), clientServiceData($overrides)))
        ->toThrow(BadRequestError::class, $mensaje);

    expect(Client::withTrashed()->count())->toBe(1);
})->with([
    'código ocupado por un borrado' => [
        ['code' => 'cli-001', 'name' => 'cliente nuevo'],
        'Ya existe un cliente con ese código, que puede haber sido eliminado',
    ],
    'nombre ocupado por un borrado' => [
        ['code' => 'cli-999', 'name' => 'agro del sur'],
        'Ya existe un cliente con ese nombre, que puede haber sido eliminado',
    ],
]);

it('comprueba el código antes que el nombre cuando los dos están ocupados', function () {
    Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR']);

    expect(fn () => clientService()->create(clientServiceAdmin(), clientServiceData(['code' => 'cli-001', 'name' => 'agro del sur'])))
        ->toThrow(BadRequestError::class, 'Ya existe un cliente con ese código, que puede haber sido eliminado');
});

/*
|--------------------------------------------------------------------------
| update()
|--------------------------------------------------------------------------
*/

it('actualiza el código y el nombre normalizándolos', function () {
    $client = Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR']);

    $updated = clientService()->update($client->id, ['code' => ' cli-002 ', 'name' => '  agro   del norte ']);

    expect($updated->id)->toBe($client->id)
        ->and($updated->code)->toBe('CLI-002')
        ->and($updated->name)->toBe('AGRO DEL NORTE');
});

it('no altera el otro campo cuando el update solo manda uno', function () {
    $client = Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR']);

    expect(clientService()->update($client->id, ['name' => 'agro del norte'])->code)->toBe('CLI-001')
        ->and(clientService()->update($client->id, ['code' => 'cli-002'])->name)->toBe('AGRO DEL NORTE');
});

it('acepta un body vacío como no-op', function () {
    $client = Client::factory()->create();

    $updated = clientService()->update($client->id, []);

    expect($updated->code)->toBe($client->code)
        ->and($updated->name)->toBe($client->name);
});

it('acepta en la edición el propio código y el propio nombre de la fila', function () {
    $client = Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR']);

    $updated = clientService()->update($client->id, ['code' => 'cli-001', 'name' => '  agro   del sur ']);

    expect($updated->code)->toBe('CLI-001')
        ->and($updated->name)->toBe('AGRO DEL SUR');
});

it('rechaza en la edición el código o el nombre de otra fila, esté viva o borrada', function (string $estado) {
    $client = Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGRO DEL SUR']);

    $otro = $estado === 'viva'
        ? Client::factory()->create(['code' => 'CLI-002', 'name' => 'AGRO DEL NORTE'])
        : Client::factory()->trashed()->create(['code' => 'CLI-002', 'name' => 'AGRO DEL NORTE']);

    expect($otro->code)->toBe('CLI-002');

    expect(fn () => clientService()->update($client->id, ['code' => 'cli-002']))
        ->toThrow(BadRequestError::class, 'Ya existe un cliente con ese código, que puede haber sido eliminado');

    expect(fn () => clientService()->update($client->id, ['name' => 'agro del norte']))
        ->toThrow(BadRequestError::class, 'Ya existe un cliente con ese nombre, que puede haber sido eliminado');

    expect($client->fresh()->code)->toBe('CLI-001')
        ->and($client->fresh()->name)->toBe('AGRO DEL SUR');
})->with(['viva', 'borrada']);

it('no reescribe al responsable del alta al editar', function () {
    $client = Client::factory()->create();

    expect(clientService()->update($client->id, ['name' => 'otro nombre'])->registered_by)->toBe($client->registered_by);
});

it('carga la relación del registrador en la edición', function () {
    $client = Client::factory()->create();

    expect(clientService()->update($client->id, ['name' => 'otro nombre'])->relationLoaded('registeredBy'))->toBeTrue();
});

it('lanza NotFoundError al editar un id inexistente', function () {
    clientService()->update(9999, ['name' => 'agro del sur']);
})->throws(NotFoundError::class, 'El cliente no existe');

it('lanza BadRequestError al editar un cliente ya borrado', function () {
    $client = Client::factory()->trashed()->create();

    clientService()->update($client->id, ['name' => 'agro del norte']);
})->throws(BadRequestError::class, 'El cliente ya fue eliminado');

/*
|--------------------------------------------------------------------------
| destroy()
|--------------------------------------------------------------------------
*/

it('borra lógicamente el cliente dejando la fila en la tabla', function () {
    $client = Client::factory()->create();

    $deleted = clientService()->destroy($client->id);

    expect($deleted->id)->toBe($client->id)
        ->and($deleted->trashed())->toBeTrue()
        ->and(Client::query()->whereKey($client->id)->exists())->toBeFalse()
        ->and(Client::withTrashed()->whereKey($client->id)->exists())->toBeTrue();
});

it('no libera el código ni el nombre del cliente que acaba de borrar', function () {
    $admin = clientServiceAdmin();
    $client = clientService()->create($admin, clientServiceData(['code' => 'cli-001', 'name' => 'agro del sur']));

    clientService()->destroy($client->id);

    expect(fn () => clientService()->create($admin, clientServiceData(['code' => 'cli-001', 'name' => 'cliente nuevo'])))
        ->toThrow(BadRequestError::class, 'Ya existe un cliente con ese código, que puede haber sido eliminado');

    expect(fn () => clientService()->create($admin, clientServiceData(['code' => 'cli-999', 'name' => 'agro del sur'])))
        ->toThrow(BadRequestError::class, 'Ya existe un cliente con ese nombre, que puede haber sido eliminado');
});

it('lanza BadRequestError en el segundo borrado, que no es idempotente', function () {
    $client = Client::factory()->create();

    clientService()->destroy($client->id);
    clientService()->destroy($client->id);
})->throws(BadRequestError::class, 'El cliente ya fue eliminado');

it('lanza NotFoundError sobre un id inexistente', function (string $method) {
    clientService()->{$method}(9999);
})->with(['getClientById', 'destroy'])->throws(NotFoundError::class, 'El cliente no existe');

/*
|--------------------------------------------------------------------------
| getClientById()
|--------------------------------------------------------------------------
*/

it('devuelve el cliente por id con su registrador cargado', function () {
    $client = Client::factory()->create();

    $found = clientService()->getClientById($client->id);

    expect($found->id)->toBe($client->id)
        ->and($found->relationLoaded('registeredBy'))->toBeTrue();
});

it('lanza NotFoundError, y no BadRequestError, al consultar un cliente borrado', function () {
    $client = Client::factory()->trashed()->create();

    clientService()->getClientById($client->id);
})->throws(NotFoundError::class, 'El cliente no existe');

/*
|--------------------------------------------------------------------------
| getClients(): listado, búsqueda y paginación
|--------------------------------------------------------------------------
*/

it('devuelve la colección completa sin limit y pagina con él', function () {
    Client::factory()->count(3)->create();

    expect(clientService()->getClients([]))->toBeInstanceOf(Collection::class)
        ->and(clientService()->getClients(['limit' => null]))->toBeInstanceOf(Collection::class)
        ->and(clientService()->getClients(['limit' => 'abc']))->toBeInstanceOf(Collection::class)
        ->and(clientService()->getClients(['limit' => '10']))->toBeInstanceOf(LengthAwarePaginator::class);
});

it('acota el tamaño de página a [10, 100]', function (string $limit, int $esperado) {
    expect(clientService()->getClients(['limit' => $limit])->perPage())->toBe($esperado);
})->with([
    'por debajo' => ['1', 10],
    'en el mínimo' => ['10', 10],
    'dentro' => ['25', 25],
    'en el máximo' => ['100', 100],
    'por encima' => ['500', 100],
]);

it('deja fuera del listado a los clientes borrados', function () {
    Client::factory()->count(2)->create();
    Client::factory()->trashed()->count(3)->create();

    expect(clientService()->getClients([]))->toHaveCount(2)
        ->and(clientService()->getClients(['limit' => '10'])->total())->toBe(2);
});

it('busca con LIKE sobre el código y sobre el nombre, normalizando el término', function (string $search, int $esperados) {
    Client::factory()->create(['code' => 'CLI-001', 'name' => 'AGROEXPORTADORA DEL SUR']);
    Client::factory()->create(['code' => 'PRO-002', 'name' => 'COMERCIALIZADORA LA CEIBA']);

    expect(clientService()->getClients(['search' => $search]))->toHaveCount($esperados);
})->with([
    'código completo en minúsculas' => ['cli-001', 1],
    'trozo del código' => ['-00', 2],
    'nombre en minúsculas' => ['agroexportadora', 1],
    'nombre con espacios de sobra' => ['  la   ceiba ', 1],
    'término compartido por los dos campos' => ['CIALIZADORA', 1],
    'sin coincidencias' => ['inexistente', 0],
    'en blanco' => ['   ', 2],
]);

it('devuelve el catálogo completo cuando la búsqueda no viene', function () {
    Client::factory()->count(2)->create();

    expect(clientService()->getClients(['search' => null]))->toHaveCount(2)
        ->and(clientService()->getClients([]))->toHaveCount(2);
});

it('devuelve los clientes ordenados por id ascendente', function () {
    $clients = Client::factory()->count(5)->create();

    $ids = $clients->pluck('id')->sort()->values()->all();

    expect(clientService()->getClients([])->pluck('id')->all())->toBe($ids);
});

it('devuelve una colección vacía cuando el catálogo está vacío', function () {
    expect(clientService()->getClients([]))->toBeInstanceOf(Collection::class)->toHaveCount(0);
});

it('carga el responsable del alta con el listado, sin N+1', function () {
    Client::factory()->count(3)->create();

    expect(clientService()->getClients([])->every(fn (Client $client) => $client->relationLoaded('registeredBy')))->toBeTrue();
});
