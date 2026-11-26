<?php

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Enums\VehicleExpenseNature;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Report\ReportServiceInterface;
use App\Interfaces\Report\SpreadsheetWriterInterface;
use App\Interfaces\Storage\FileStorageServiceInterface;
use App\Interfaces\Trip\TripServiceInterface;
use App\Interfaces\TripEmergencyExpense\TripEmergencyExpenseServiceInterface;
use App\Interfaces\VehicleExpense\VehicleExpenseServiceInterface;
use App\Models\Carrier;
use App\Models\FinishedProduct;
use App\Models\Trip;
use App\Models\TripEmergencyExpense;
use App\Models\TripFinishedProduct;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Services\Report\ReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Doubles\InMemoryFileStorageService;

function reportAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

function reportCarrierOwner(Carrier $carrier): User
{
    return User::query()->findOrFail($carrier->user_id);
}

/**
 * The key of the stored report, taken from the URL the fake disk hands back.
 */
function reportKeyFromUrl(string $url): string
{
    return substr($url, strpos($url, 'reports/'));
}

/**
 * Rows of the stored report, header included, read back from the fake disk.
 *
 * @return list<list<mixed>>
 */
function storedReportRows(string $url): array
{
    $path = tempnam(sys_get_temp_dir(), 'report');
    file_put_contents($path, Storage::disk(config('filesystems.default'))->get(reportKeyFromUrl($url)));

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
 * A `ReportService` capped at `$maxRows`, with the real collaborators underneath.
 */
function cappedReportService(int $maxRows): ReportService
{
    return new ReportService(
        app(TripServiceInterface::class),
        app(VehicleExpenseServiceInterface::class),
        app(TripEmergencyExpenseServiceInterface::class),
        app(SpreadsheetWriterInterface::class),
        app(FileStorageServiceInterface::class),
        $maxRows,
    );
}

it('está bindeado en el contenedor como implementación del contrato', function () {
    expect(app(ReportServiceInterface::class))->toBeInstanceOf(ReportService::class);
});

/*
|--------------------------------------------------------------------------
| exportTrips
|--------------------------------------------------------------------------
*/

it('exporta los viajes filtrados a un xlsx en el disco por defecto y devuelve su URL', function () {
    Trip::factory()->finished()->create(['order' => 'ORD-0001', 'recolection_date' => '2026-03-01 08:00:00']);
    Trip::factory()->finished()->create(['order' => 'ORD-0002', 'recolection_date' => '2026-03-02 08:00:00', 'bonus' => 250.5, 'cargo_insurance' => 99.75]);
    Trip::factory()->create(['order' => 'ORD-0003']);

    $result = app(ReportServiceInterface::class)->exportTrips(reportAdmin(), ['status' => TripStatus::Finished->value]);

    expect($result['rows'])->toBe(2)
        ->and($result['total'])->toBe(2)
        ->and($result['truncated'])->toBeFalse()
        ->and($result['fileName'])->toMatch('/^viajes-\d{4}-\d{2}-\d{2}-\d{6}\.xlsx$/')
        ->and($result['url'])->toStartWith('https://')
        ->and($result['url'])->toEndWith('.xlsx');

    Storage::disk(config('filesystems.default'))->assertExists(reportKeyFromUrl($result['url']));

    $rows = storedReportRows($result['url']);

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBe([
            'Id', 'Orden', 'Cliente', 'Estado', 'Naviera', 'Punto de partida', 'Puerto', 'Contenedor',
            'Fecha recolección', 'Fecha embarque', 'Inicio', 'Fin', 'Km estimados',
            'Horas estimadas', 'Observaciones', 'Piloto', 'Placa', 'Registrado por',
            'Bonificación (Q)', 'Seguro de carga (Q)',
        ])
        ->and($rows[0])->not->toContain('OV')
        ->and(array_column(array_slice($rows, 1), 1))->toBe(['ORD-0002', 'ORD-0001'])
        ->and($rows[1][2])->toBe(Trip::query()->firstWhere('order', 'ORD-0002')->client->name)
        ->and($rows[2][2])->toBe(Trip::query()->firstWhere('order', 'ORD-0001')->client->name)
        ->and($rows[1][3])->toBe('Finalizado')
        ->and($rows[1][12])->toBeNumeric()
        ->and($rows[1][18])->toBe(250.5)
        ->and($rows[2][18])->toBe('')
        ->and($rows[1][19])->toBe(99.75)
        ->and($rows[2][19])->toBe('');
});

it('carga los clientes de los viajes exportados con una sola consulta', function () {
    Trip::factory()->count(3)->create();
    $admin = reportAdmin();

    DB::enableQueryLog();
    app(ReportServiceInterface::class)->exportTrips($admin, []);
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "clients"'));
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
});

it('ignora el limit y exporta el conjunto completo', function () {
    Trip::factory()->count(3)->create();

    $result = app(ReportServiceInterface::class)->exportTrips(reportAdmin(), ['limit' => '1']);

    expect($result['rows'])->toBe(3)
        ->and($result['total'])->toBe(3);
});

it('recorta al tope de filas y lo señala con truncated', function () {
    Trip::factory()->count(3)->create();

    $result = cappedReportService(2)->exportTrips(reportAdmin(), []);

    expect($result['rows'])->toBe(2)
        ->and($result['total'])->toBe(3)
        ->and($result['truncated'])->toBeTrue()
        ->and(storedReportRows($result['url']))->toHaveCount(3);
});

it('exporta un archivo con solo la cabecera cuando ningún viaje cumple los filtros', function () {
    $result = app(ReportServiceInterface::class)->exportTrips(reportAdmin(), ['status' => TripStatus::InRoute->value]);

    expect($result['rows'])->toBe(0)
        ->and($result['total'])->toBe(0)
        ->and($result['truncated'])->toBeFalse()
        ->and(storedReportRows($result['url']))->toHaveCount(1);
});

it('aplica al transportista el ámbito de sus viajes: la bolsa más los que tomó su empresa', function () {
    $carrier = Carrier::factory()->create();
    $other = Carrier::factory()->create();

    Trip::factory()->create(['order' => 'ORD-POOL']);
    Trip::factory()->assigned()->create(['order' => 'ORD-MINE', 'assigned_by' => $carrier->user_id]);
    Trip::factory()->assigned()->create(['order' => 'ORD-THEIRS', 'assigned_by' => $other->user_id]);

    $result = app(ReportServiceInterface::class)->exportTrips(reportCarrierOwner($carrier), []);
    $orders = array_column(array_slice(storedReportRows($result['url']), 1), 1);

    expect($result['total'])->toBe(2)
        ->and($orders)->toContain('ORD-POOL', 'ORD-MINE')
        ->and($orders)->not->toContain('ORD-THEIRS');
});

/*
|--------------------------------------------------------------------------
| exportVehicleExpenses
|--------------------------------------------------------------------------
*/

it('exporta los gastos del vehículo con la factura como URL y el total de todos los filtrados', function () {
    $vehicle = Vehicle::factory()->create();
    VehicleExpense::factory()->invoiced()->create(['vehicle_id' => $vehicle->id, 'amount' => 300, 'nature' => VehicleExpenseNature::Preventive, 'expense_date' => '2026-03-02']);
    VehicleExpense::factory()->create(['vehicle_id' => $vehicle->id, 'amount' => 200, 'nature' => VehicleExpenseNature::Corrective, 'expense_date' => '2026-03-01']);
    VehicleExpense::factory()->create(['amount' => 999]);

    $result = app(ReportServiceInterface::class)->exportVehicleExpenses(reportAdmin(), ['vehicleId' => $vehicle->id]);
    $rows = storedReportRows($result['url']);

    expect($result['rows'])->toBe(2)
        ->and($result['total'])->toBe(2)
        ->and($result['totalAmount'])->toBe('500.00')
        ->and($result['truncated'])->toBeFalse()
        ->and($result['fileName'])->toStartWith("gastos-vehiculo-{$vehicle->id}-")
        ->and($rows[0])->toBe(['Id', 'Categoría', 'Naturaleza', 'Monto (Q)', 'Fecha', 'Descripción', 'Facturado', 'Factura', 'Registrado por', 'Creado'])
        ->and($rows[1][2])->toBe('Preventivo')
        ->and($rows[1][3])->toEqual(300)
        ->and($rows[1][6])->toBe('Sí')
        ->and($rows[1][7])->toStartWith('https://')
        ->and($rows[2][2])->toBe('Correctivo')
        ->and($rows[2][6])->toBe('No')
        ->and($rows[2][7])->toBe('');
});

it('mantiene el total de todos los gastos aunque el tope recorte las filas', function () {
    $vehicle = Vehicle::factory()->create();
    VehicleExpense::factory()->count(3)->create(['vehicle_id' => $vehicle->id, 'amount' => 100]);

    $result = cappedReportService(1)->exportVehicleExpenses(reportAdmin(), ['vehicleId' => $vehicle->id]);

    expect($result['rows'])->toBe(1)
        ->and($result['total'])->toBe(3)
        ->and($result['totalAmount'])->toBe('300.00')
        ->and($result['truncated'])->toBeTrue();
});

it('propaga el 403 de un vehículo ajeno y el 404 de uno inexistente', function () {
    $foreign = Vehicle::factory()->create(['carrier_id' => Carrier::factory()->create()->id]);
    $owner = reportCarrierOwner(Carrier::factory()->create());
    $service = app(ReportServiceInterface::class);

    expect(fn () => $service->exportVehicleExpenses($owner, ['vehicleId' => $foreign->id]))->toThrow(ForbiddenError::class)
        ->and(fn () => $service->exportVehicleExpenses(reportAdmin(), ['vehicleId' => 999999]))->toThrow(NotFoundError::class);

    expect(Storage::disk(config('filesystems.default'))->allFiles('reports'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| exportTripEmergencyExpenses (SPEC 39)
|--------------------------------------------------------------------------
*/

it('exporta los gastos emergentes del viaje con el monto como número, el comprobante como URL y el total', function () {
    $trip = Trip::factory()->inRoute()->create();
    TripEmergencyExpense::factory()->withReceipt()->create(['trip_id' => $trip->id, 'amount' => 300.5, 'description' => 'Llanta pinchada']);
    TripEmergencyExpense::factory()->create(['trip_id' => $trip->id, 'amount' => 200, 'description' => 'Grúa']);
    TripEmergencyExpense::factory()->create(['amount' => 999]);

    $result = app(ReportServiceInterface::class)->exportTripEmergencyExpenses(reportAdmin(), ['tripId' => $trip->id]);
    $rows = storedReportRows($result['url']);

    expect($result['rows'])->toBe(2)
        ->and($result['total'])->toBe(2)
        ->and($result['totalAmount'])->toBe('500.50')
        ->and($result['truncated'])->toBeFalse()
        ->and($result['fileName'])->toMatch("/^gastos-emergentes-viaje-{$trip->id}-\\d{4}-\\d{2}-\\d{2}-\\d{6}\\.xlsx$/")
        ->and(reportKeyFromUrl($result['url']))->toMatch('#^reports/[0-9a-f-]{36}\.xlsx$#')
        ->and($rows[0])->toBe(['Id', 'Monto (Q)', 'Descripción', 'Comprobante', 'Registrado por', 'Creado', 'Actualizado'])
        ->and($rows[1][1])->toEqual(300.5)
        ->and($rows[1][2])->toBe('Llanta pinchada')
        ->and($rows[1][3])->toStartWith('https://')->toContain('trip-emergency-expenses/')
        ->and($rows[2][2])->toBe('Grúa')
        ->and($rows[2][3])->toBe('');
});

it('mantiene el total de todos los gastos emergentes aunque el tope recorte las filas', function () {
    $trip = Trip::factory()->inRoute()->create();
    TripEmergencyExpense::factory()->count(3)->create(['trip_id' => $trip->id, 'amount' => 100]);

    $result = cappedReportService(1)->exportTripEmergencyExpenses(reportAdmin(), ['tripId' => $trip->id]);

    expect($result['rows'])->toBe(1)
        ->and($result['total'])->toBe(3)
        ->and($result['totalAmount'])->toBe('300.00')
        ->and($result['truncated'])->toBeTrue();
});

it('propaga el 404, el 403 de ámbito y el 403 de shipment sin subir nada', function () {
    $trip = Trip::factory()->inRoute()->create();
    $stranger = reportCarrierOwner(Carrier::factory()->create());
    $service = app(ReportServiceInterface::class);

    expect(fn () => $service->exportTripEmergencyExpenses(reportAdmin(), ['tripId' => 999999]))->toThrow(NotFoundError::class)
        ->and(fn () => $service->exportTripEmergencyExpenses($stranger, ['tripId' => $trip->id]))->toThrow(ForbiddenError::class)
        ->and(fn () => $service->exportTripEmergencyExpenses(User::factory()->create(['role' => UserRole::Shipment]), ['tripId' => $trip->id]))->toThrow(ForbiddenError::class);

    expect(Storage::disk(config('filesystems.default'))->allFiles('reports'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Storage
|--------------------------------------------------------------------------
*/

it('traduce un fallo del almacenamiento a BadRequestError', function () {
    app()->instance(FileStorageServiceInterface::class, new InMemoryFileStorageService(failing: true));
    Trip::factory()->create();

    expect(fn () => app(ReportServiceInterface::class)->exportTrips(reportAdmin(), []))->toThrow(BadRequestError::class);
});

it('funciona con cualquier implementación del contrato de almacenamiento', function () {
    $storage = new InMemoryFileStorageService;
    app()->instance(FileStorageServiceInterface::class, $storage);
    Trip::factory()->create();

    $result = app(ReportServiceInterface::class)->exportTrips(reportAdmin(), []);

    expect($storage->keys())->toHaveCount(1)
        ->and($storage->keys()[0])->toStartWith('reports/')
        ->and($result['url'])->toBe('https://doble.test/'.$storage->keys()[0]);
});

/*
|--------------------------------------------------------------------------
| downloadTrips
|--------------------------------------------------------------------------
*/

/**
 * Rows of a downloaded report, header included, read back from its bytes.
 *
 * @return list<list<mixed>>
 */
function downloadedReportRows(string $contents): array
{
    $path = tempnam(sys_get_temp_dir(), 'report');
    file_put_contents($path, $contents);

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
 * The headers of the report, in order; «OV» and «PO» (the `reference`) second and third for
 * every role, «Producto» and «Cajas» only for the roles that see the products.
 *
 * @return list<string>
 */
function tripReportHeaders(bool $withProducts = true): array
{
    return [
        'Orden', 'OV', 'PO', 'Cliente',
        ...($withProducts ? ['Producto', 'Cajas'] : []),
        'Naviera', 'Destino', 'Contenedor', 'Punto de Partida', 'Fecha de recolección',
        'Fecha de embarque', 'Booking', 'Punto de llegada', 'Transporte', 'Status', 'Observaciones',
    ];
}

/**
 * The filters of a September 2026 report, plus whatever the test adds.
 *
 * @param  array<string, string>  $extra
 * @return array<string, string>
 */
function septemberReport(array $extra = []): array
{
    return ['dateFrom' => '2026-09-01', 'dateTo' => '2026-09-30', ...$extra];
}

/**
 * A `ReportService` capped at `$maxRows` over the given writer.
 */
function downloadReportService(SpreadsheetWriterInterface $writer, int $maxRows = ReportService::MAX_ROWS): ReportService
{
    return new ReportService(
        app(TripServiceInterface::class),
        app(VehicleExpenseServiceInterface::class),
        app(TripEmergencyExpenseServiceInterface::class),
        $writer,
        app(FileStorageServiceInterface::class),
        $maxRows,
    );
}

it('descarga los viajes del rango con las columnas del negocio en su orden, sin subir nada al disco', function () {
    $trip = Trip::factory()->finished()->create([
        'order' => 'ORD-0001',
        'destination' => 'Bodega Rotterdam',
        'transport' => 'Rastra 40 pies',
        'booking' => 'BK-778',
        'ov' => 'OV-778',
        'reference' => 'PO-778',
        'recolection_date' => '2026-09-10 08:30:00',
        'ship_date' => '2026-09-12 14:00:00',
        'bonus' => 250.5,
        'cargo_insurance' => 99.75,
        'observations' => 'Entregar en muelle 3',
    ])->load('client', 'shippingLine', 'departurePoint', 'location');

    $result = app(ReportServiceInterface::class)->downloadTrips(
        User::factory()->create(['role' => UserRole::User]),
        septemberReport(),
    );

    $rows = downloadedReportRows($result['contents']);

    expect($result['fileName'])->toBe('viajes-2026-09-01_2026-09-30.xlsx')
        ->and(Storage::disk(config('filesystems.default'))->allFiles())->toBeEmpty()
        ->and($rows)->toHaveCount(2)
        ->and($rows[0])->toBe(tripReportHeaders(withProducts: false))
        ->and($rows[1])->toBe([
            'ORD-0001', 'OV-778', 'PO-778', $trip->client->name, $trip->shippingLine->name, 'Bodega Rotterdam',
            $trip->container, $trip->departurePoint->name, '10-09-2026 08:30:00 AM',
            '12-09-2026 02:00:00 PM', 'BK-778', $trip->location->name, 'Rastra 40 pies', 'Finalizado',
            'Entregar en muelle 3',
        ]);
});

it('deja vacías las celdas nulas y pone el estado en español antes de las observaciones', function () {
    Trip::factory()->create([
        'recolection_date' => '2026-09-10 08:30:00', 'booking' => null, 'ov' => null, 'reference' => null,
        'observations' => null,
    ]);

    $result = app(ReportServiceInterface::class)->downloadTrips(reportAdmin(), septemberReport());
    $row = downloadedReportRows($result['contents'])[1];

    expect($row)->toHaveCount(17)
        ->and($row[16])->toBe('')
        ->and($row[1])->toBe('')
        ->and($row[2])->toBe('')
        ->and([$row[4], $row[5]])->toBe(['', ''])
        ->and($row[12])->toBe('')
        ->and($row[15])->toBe('Pendiente');
});

it('filtra por día completo con bordes inclusivos, sin borrados y en orden de recolección desc', function () {
    Trip::factory()->create(['order' => 'ORD-FIRST', 'recolection_date' => '2026-09-01 00:00:00']);
    Trip::factory()->create(['order' => 'ORD-LAST', 'recolection_date' => '2026-09-30 23:59:59']);
    Trip::factory()->create(['order' => 'ORD-BEFORE', 'recolection_date' => '2026-08-31 23:59:59']);
    Trip::factory()->create(['order' => 'ORD-AFTER', 'recolection_date' => '2026-10-01 00:00:00']);
    Trip::factory()->trashed()->create(['order' => 'ORD-DELETED', 'recolection_date' => '2026-09-15 10:00:00']);

    $result = app(ReportServiceInterface::class)->downloadTrips(reportAdmin(), septemberReport(['limit' => '1']));

    expect(array_column(array_slice(downloadedReportRows($result['contents']), 1), 0))->toBe(['ORD-LAST', 'ORD-FIRST']);
});

it('devuelve un archivo con solo la cabecera cuando el rango no tiene viajes', function () {
    $result = app(ReportServiceInterface::class)->downloadTrips(reportAdmin(), septemberReport());

    expect(downloadedReportRows($result['contents']))->toHaveCount(1);
});

it('aplica al transportista el ámbito del listado en la descarga', function () {
    $carrier = Carrier::factory()->create();
    $other = Carrier::factory()->create();
    $date = ['recolection_date' => '2026-09-10 08:00:00'];

    Trip::factory()->create(['order' => 'ORD-POOL', ...$date]);
    Trip::factory()->assigned()->create(['order' => 'ORD-MINE', 'assigned_by' => $carrier->user_id, ...$date]);
    Trip::factory()->assigned()->create(['order' => 'ORD-THEIRS', 'assigned_by' => $other->user_id, ...$date]);

    $result = app(ReportServiceInterface::class)->downloadTrips(reportCarrierOwner($carrier), septemberReport());
    $orders = array_column(array_slice(downloadedReportRows($result['contents']), 1), 0);

    expect($orders)->toHaveCount(2)
        ->and($orders)->toContain('ORD-POOL', 'ORD-MINE');
});

it('responde 400 por exceso de viajes antes de escribir el archivo, citando MAX_ROWS', function () {
    Trip::factory()->count(3)->create(['recolection_date' => '2026-09-10 08:00:00']);

    $writer = new class implements SpreadsheetWriterInterface
    {
        public int $calls = 0;

        public function write(array $headers, iterable $rows): string
        {
            $this->calls++;

            return '';
        }
    };

    expect(fn () => downloadReportService($writer, 2)->downloadTrips(reportAdmin(), septemberReport()))
        ->toThrow(BadRequestError::class, 'El reporte excede 5000 viajes; acota el rango de fechas')
        ->and($writer->calls)->toBe(0);
});

it('incluye Producto y Cajas solo para los roles que ven los productos, sin columnas de dinero', function (UserRole $role, bool $withProducts) {
    Trip::factory()->create(['recolection_date' => '2026-09-10 08:00:00']);

    $user = $role === UserRole::Carrier
        ? reportCarrierOwner(Carrier::factory()->create())
        : User::factory()->create(['role' => $role]);

    $headers = downloadedReportRows(app(ReportServiceInterface::class)->downloadTrips($user, septemberReport())['contents'])[0];

    expect($headers)->toBe(tripReportHeaders($withProducts));
})->with([
    'administrator' => [UserRole::Administrator, true],
    'manager' => [UserRole::Manager, true],
    'export' => [UserRole::Export, true],
    'shipment' => [UserRole::Shipment, true],
    'carrier' => [UserRole::Carrier, false],
    'user' => [UserRole::User, false],
]);

it('escribe una fila por línea de producto en orden de línea, aunque el SKU esté borrado', function () {
    $trip = Trip::factory()->create(['order' => 'ORD-PRODUCTS', 'recolection_date' => '2026-09-10 08:00:00']);
    $first = FinishedProduct::factory()->create(['code' => 'CODE1', 'name' => 'BROCOLI', 'client_id' => $trip->client_id]);
    $second = FinishedProduct::factory()->create(['code' => 'CODE2', 'name' => 'EJOTE', 'client_id' => $trip->client_id]);

    TripFinishedProduct::factory()->create(['trip_id' => $trip->id, 'finished_product_id' => $first->id, 'boxes' => 120]);
    TripFinishedProduct::factory()->create(['trip_id' => $trip->id, 'finished_product_id' => $second->id, 'boxes' => 40]);
    $second->delete();

    Trip::factory()->create(['order' => 'ORD-EMPTY', 'recolection_date' => '2026-09-05 08:00:00']);

    $rows = downloadedReportRows(app(ReportServiceInterface::class)->downloadTrips(reportAdmin(), septemberReport())['contents']);
    $withoutProduct = static fn (array $row): array => [...array_slice($row, 0, 4), ...array_slice($row, 6)];

    expect($rows)->toHaveCount(4)
        ->and(array_column(array_slice($rows, 1), 0))->toBe(['ORD-PRODUCTS', 'ORD-PRODUCTS', 'ORD-EMPTY'])
        ->and($withoutProduct($rows[1]))->toBe($withoutProduct($rows[2]))
        ->and(array_slice($rows[1], 4, 2))->toBe(['BROCOLI', 120])
        ->and(array_slice($rows[2], 4, 2))->toBe(['EJOTE', 40])
        ->and(array_slice($rows[3], 4, 2))->toBe(['', '']);
});
