<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\FinishedProduct;
use App\Models\Trip;
use App\Models\TripFinishedProduct;
use App\Models\User;
use App\Services\Report\ReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
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
     */
    function asUser(User $user): TestCase
    {
        resetAuthState();

        return test()->withToken(JWTAuth::fromUser($user))->withHeader('Accept', 'application/json');
    }
}

/**
 * The report URI for September 2026, plus any extra query string.
 */
function tripsReportUri(string $extra = ''): string
{
    return '/api/reports/trips?dateFrom=2026-09-01&dateTo=2026-09-30'.$extra;
}

/**
 * A user of the given role able to reach the report; a carrier comes with its company.
 */
function tripsReportUser(UserRole $role): User
{
    return $role === UserRole::Carrier
        ? User::query()->findOrFail(Carrier::factory()->create()->user_id)
        : userWithRole($role);
}

/**
 * Rows of the spreadsheet a response carries, header included.
 *
 * @return list<list<mixed>>
 */
function tripsReportRows(TestResponse $response): array
{
    $path = tempnam(sys_get_temp_dir(), 'report');
    file_put_contents($path, $response->getContent());

    $reader = new Reader;
    $reader->open($path);

    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
    }

    $reader->close();
    unlink($path);

    return $rows;
}

/**
 * The orders of the data rows of a report response.
 *
 * @return list<string>
 */
function tripsReportOrders(TestResponse $response): array
{
    return array_column(array_slice(tripsReportRows($response), 1), 1);
}

/**
 * The 22 base headers every authorized role gets.
 *
 * @return list<string>
 */
function tripsReportBaseHeaders(): array
{
    return [
        'Id', 'Orden', 'Estado', 'Cliente', 'Naviera', 'Punto de partida', 'Puerto',
        'Destino final', 'Transporte', 'Contenedor', 'Fecha recolección', 'Fecha embarque',
        'Inicio', 'Fin', 'Km estimados', 'Horas estimadas', 'Km reales', 'Horas reales',
        'Observaciones', 'Piloto', 'Placa', 'Registrado por',
    ];
}

/**
 * A trip picked up in the middle of September 2026.
 *
 * @param  array<string, mixed>  $attributes
 */
function septemberTrip(array $attributes = []): Trip
{
    return Trip::factory()->create(['recolection_date' => '2026-09-15 10:00:00', ...$attributes]);
}

/*
|--------------------------------------------------------------------------
| Ruta y acceso
|--------------------------------------------------------------------------
*/

it('expone una sola ruta, GET api/reports/trips, con nombre reports.trips', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/reports'));

    expect($routes)->toHaveCount(1)
        ->and($routes->first()->uri())->toBe('api/reports/trips')
        ->and($routes->first()->methods())->toContain('GET')
        ->and($routes->first()->getName())->toBe('reports.trips');
});

it('rechaza con 401 sin token', function () {
    $this->getJson(tripsReportUri())
        ->assertUnauthorized()
        ->assertExactJson([
            'statusCode' => 401,
            'message' => 'El token de sesión no es válido o ha expirado',
            'data' => null,
        ]);
});

it('rechaza con 403 al piloto', function () {
    asUser(userWithRole(UserRole::Pilot))->getJson(tripsReportUri())
        ->assertForbidden()
        ->assertExactJson([
            'statusCode' => 403,
            'message' => 'No tienes permisos para acceder a este recurso',
            'data' => null,
        ]);
});

it('entrega el xlsx con las cabeceras exactas y la matriz de columnas de cada rol', function (UserRole $role, bool $withProducts) {
    septemberTrip();

    $response = asUser(tripsReportUser($role))->get(tripsReportUri());

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->assertHeader('Content-Disposition', 'attachment; filename="viajes-2026-09-01_2026-09-30.xlsx"');

    $headers = tripsReportRows($response)[0];

    expect($headers)->toBe($withProducts
        ? [...tripsReportBaseHeaders(), 'Productos', 'Total de cajas']
        : tripsReportBaseHeaders())
        ->and(Storage::disk(config('filesystems.default'))->allFiles('reports'))->toBeEmpty();
})->with([
    'administrator' => [UserRole::Administrator, true],
    'manager' => [UserRole::Manager, true],
    'export' => [UserRole::Export, true],
    'shipment' => [UserRole::Shipment, true],
    'carrier' => [UserRole::Carrier, false],
    'user' => [UserRole::User, false],
]);

/*
|--------------------------------------------------------------------------
| Validación
|--------------------------------------------------------------------------
*/

it('responde 422 con un rango ausente, mal formado o invertido', function (string $query, string $field) {
    asUser(userWithRole(UserRole::Administrator))->getJson('/api/reports/trips'.$query)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'sin dateFrom' => ['?dateTo=2026-09-30', 'dateFrom'],
    'sin dateTo' => ['?dateFrom=2026-09-01', 'dateTo'],
    'mes inválido' => ['?dateFrom=2026-13-01&dateTo=2026-12-31', 'dateFrom'],
    'formato d-m-Y' => ['?dateFrom=01-09-2026&dateTo=2026-09-30', 'dateFrom'],
    'dateTo anterior' => ['?dateFrom=2026-09-30&dateTo=2026-09-01', 'dateTo'],
]);

it('devuelve los mensajes de validación en español', function () {
    asUser(userWithRole(UserRole::Administrator))->getJson('/api/reports/trips?dateFrom=2026-09-30&dateTo=2026-09-01')
        ->assertJsonValidationErrors(['dateTo' => 'La fecha final no puede ser anterior a la fecha inicial']);

    asUser(userWithRole(UserRole::Administrator))->getJson('/api/reports/trips')
        ->assertJsonValidationErrors([
            'dateFrom' => 'La fecha inicial es obligatoria',
            'dateTo' => 'La fecha final es obligatoria',
        ]);
});

it('acepta un rango de un solo día', function () {
    septemberTrip(['order' => 'ORD-DAY']);

    $response = asUser(userWithRole(UserRole::Administrator))->get('/api/reports/trips?dateFrom=2026-09-15&dateTo=2026-09-15');

    $response->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="viajes-2026-09-15_2026-09-15.xlsx"');

    expect(tripsReportOrders($response))->toBe(['ORD-DAY']);
});

it('aplica un filtro opcional válido e ignora uno inválido y el limit', function () {
    septemberTrip(['order' => 'ORD-PENDING']);
    Trip::factory()->finished()->create(['order' => 'ORD-FINISHED', 'recolection_date' => '2026-09-14 10:00:00']);

    $admin = userWithRole(UserRole::Administrator);

    expect(tripsReportOrders(asUser($admin)->get(tripsReportUri('&status='.TripStatus::Finished->value))))->toBe(['ORD-FINISHED'])
        ->and(tripsReportOrders(asUser($admin)->get(tripsReportUri('&status=basura'))))->toBe(['ORD-PENDING', 'ORD-FINISHED'])
        ->and(tripsReportOrders(asUser($admin)->get(tripsReportUri('&limit=1'))))->toBe(['ORD-PENDING', 'ORD-FINISHED']);
});

/*
|--------------------------------------------------------------------------
| Contenido
|--------------------------------------------------------------------------
*/

it('incluye los bordes del rango por día completo, excluye lo de fuera y los borrados, y ordena por recolección desc', function () {
    septemberTrip(['order' => 'ORD-FIRST', 'recolection_date' => '2026-09-01 00:00:00']);
    septemberTrip(['order' => 'ORD-LAST', 'recolection_date' => '2026-09-30 23:59:59']);
    septemberTrip(['order' => 'ORD-MIDDLE']);
    septemberTrip(['order' => 'ORD-BEFORE', 'recolection_date' => '2026-08-31 23:59:59']);
    septemberTrip(['order' => 'ORD-AFTER', 'recolection_date' => '2026-10-01 00:00:00']);
    Trip::factory()->trashed()->create(['order' => 'ORD-DELETED', 'recolection_date' => '2026-09-15 10:00:00']);

    $response = asUser(userWithRole(UserRole::Administrator))->get(tripsReportUri());

    expect(tripsReportOrders($response))->toBe(['ORD-LAST', 'ORD-MIDDLE', 'ORD-FIRST']);
});

it('desempata por id desc dos viajes del mismo instante', function () {
    $older = septemberTrip();
    $newer = septemberTrip();

    $rows = tripsReportRows(asUser(userWithRole(UserRole::Administrator))->get(tripsReportUri()));

    expect(array_column(array_slice($rows, 1), 0))->toBe([$newer->id, $older->id]);
});

it('traduce el estado, formatea las fechas, deja números en km y horas y vacía piloto y placa en la bolsa', function () {
    Trip::factory()->finished()->create([
        'order' => 'ORD-DONE',
        'recolection_date' => '2026-09-10 08:30:00',
        'start_date' => '2026-09-10 09:00:00',
        'end_date' => '2026-09-10 15:00:00',
        'estimated_kilometers' => 104.32,
        'traveled_hours' => 2.1,
    ]);
    Trip::factory()->inRoute()->create(['order' => 'ORD-ROUTE', 'recolection_date' => '2026-09-09 08:00:00']);
    septemberTrip(['order' => 'ORD-POOL', 'recolection_date' => '2026-09-08 08:00:00']);

    $rows = tripsReportRows(asUser(userWithRole(UserRole::Administrator))->get(tripsReportUri()));
    [$done, $route, $pool] = array_slice($rows, 1);

    expect([$done[2], $route[2], $pool[2]])->toBe(['Finalizado', 'En ruta', 'Pendiente'])
        ->and($done[10])->toBe('10-09-2026 08:30:00 AM')
        ->and($done[13])->toBe('10-09-2026 03:00:00 PM')
        ->and($done[14])->toBe(104.32)
        ->and($done[17])->toBe(2.1)
        ->and($pool[13])->toBe('')
        ->and($pool[16])->toBe('')
        ->and($pool[17])->toBe('')
        ->and($pool[19])->toBe('')
        ->and($pool[20])->toBe('');
});

it('responde 200 con solo la fila de encabezados cuando el rango no tiene viajes', function () {
    $response = asUser(userWithRole(UserRole::Administrator))->get(tripsReportUri());

    $response->assertOk();

    expect(tripsReportRows($response))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Ámbito
|--------------------------------------------------------------------------
*/

it('limita al transportista a la bolsa y a los viajes de su empresa', function () {
    $carrier = Carrier::factory()->create();
    $other = Carrier::factory()->create();

    septemberTrip(['order' => 'ORD-POOL']);
    Trip::factory()->assigned()->create(['order' => 'ORD-MINE', 'assigned_by' => $carrier->user_id, 'recolection_date' => '2026-09-14 10:00:00']);
    Trip::factory()->assigned()->create(['order' => 'ORD-THEIRS', 'assigned_by' => $other->user_id, 'recolection_date' => '2026-09-13 10:00:00']);

    $owner = User::query()->findOrFail($carrier->user_id);

    expect(tripsReportOrders(asUser($owner)->get(tripsReportUri())))->toBe(['ORD-POOL', 'ORD-MINE']);
});

it('entrega a user y shipment los viajes de todas las empresas', function (UserRole $role) {
    septemberTrip(['order' => 'ORD-POOL']);
    Trip::factory()->assigned()->create(['order' => 'ORD-ONE', 'recolection_date' => '2026-09-14 10:00:00']);
    Trip::factory()->assigned()->create(['order' => 'ORD-TWO', 'recolection_date' => '2026-09-13 10:00:00']);

    expect(tripsReportOrders(asUser(userWithRole($role))->get(tripsReportUri())))->toBe(['ORD-POOL', 'ORD-ONE', 'ORD-TWO']);
})->with([
    'user' => [UserRole::User],
    'shipment' => [UserRole::Shipment],
]);

/*
|--------------------------------------------------------------------------
| Productos
|--------------------------------------------------------------------------
*/

it('resume los productos en orden de línea, suma las cajas y conserva el SKU borrado', function () {
    $trip = septemberTrip(['order' => 'ORD-PRODUCTS']);
    $first = FinishedProduct::factory()->create(['code' => 'CODE1', 'client_id' => $trip->client_id]);
    $second = FinishedProduct::factory()->create(['code' => 'CODE2', 'client_id' => $trip->client_id]);

    TripFinishedProduct::factory()->create(['trip_id' => $trip->id, 'finished_product_id' => $first->id, 'boxes' => 120]);
    TripFinishedProduct::factory()->create(['trip_id' => $trip->id, 'finished_product_id' => $second->id, 'boxes' => 40]);
    $second->delete();

    septemberTrip(['order' => 'ORD-EMPTY', 'recolection_date' => '2026-09-05 10:00:00']);

    [, $withLines, $withoutLines] = tripsReportRows(asUser(userWithRole(UserRole::Export))->get(tripsReportUri()));

    expect(array_slice($withLines, 22))->toBe(['CODE1 × 120 cajas; CODE2 × 40 cajas', 160])
        ->and(array_slice($withoutLines, 22))->toBe(['', 0]);
});

/*
|--------------------------------------------------------------------------
| Tope de filas y rendimiento
|--------------------------------------------------------------------------
*/

it('responde 400 con el sobre JSON cuando el rango excede el tope de filas', function () {
    app()->when(ReportService::class)->needs('$maxRows')->give(2);

    septemberTrip();
    septemberTrip();
    septemberTrip();

    asUser(userWithRole(UserRole::Administrator))->getJson(tripsReportUri())
        ->assertBadRequest()
        ->assertExactJson([
            'statusCode' => 400,
            'message' => 'El reporte excede 5000 viajes; acota el rango de fechas',
            'data' => null,
        ]);
});

it('no crece en consultas con el número de viajes ni con el de líneas de productos', function () {
    $admin = userWithRole(UserRole::Administrator);

    $count = function (string $uri) use ($admin): int {
        asUser($admin);
        DB::flushQueryLog();
        DB::enableQueryLog();

        test()->get($uri)->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    /** Cada escenario en su propio mes, para contar sobre conjuntos disjuntos. */
    $seed = function (string $month, int $trips, int $lines): string {
        foreach (range(1, $trips) as $ignored) {
            $trip = Trip::factory()->assigned()->create(['recolection_date' => "2026-{$month}-15 10:00:00"]);

            foreach (range(1, $lines) as $ignoredLine) {
                TripFinishedProduct::factory()->create([
                    'trip_id' => $trip->id,
                    'finished_product_id' => FinishedProduct::factory()->create(['client_id' => $trip->client_id])->id,
                ]);
            }
        }

        return "/api/reports/trips?dateFrom=2026-{$month}-01&dateTo=2026-{$month}-28";
    };

    $oneTrip = $seed('01', 1, 1);
    $tenTrips = $seed('02', 10, 1);
    $fiveLines = $seed('03', 1, 5);

    expect($count($tenTrips))->toBe($count($oneTrip))
        ->and($count($fiveLines))->toBe($count($oneTrip));
});
