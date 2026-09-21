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
use App\Interfaces\VehicleExpense\VehicleExpenseServiceInterface;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleExpense;
use App\Services\Report\ReportService;
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
    Trip::factory()->finished()->create(['order' => 'ORD-0002', 'recolection_date' => '2026-03-02 08:00:00']);
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
            'Id', 'Orden', 'Estado', 'Naviera', 'Punto de partida', 'Puerto', 'Contenedor',
            'Fecha recolección', 'Fecha embarque', 'Inicio', 'Fin', 'Km estimados',
            'Horas estimadas', 'Observaciones', 'Piloto', 'Placa', 'Registrado por',
        ])
        ->and(array_column(array_slice($rows, 1), 1))->toBe(['ORD-0002', 'ORD-0001'])
        ->and($rows[1][2])->toBe('Finalizado')
        ->and($rows[1][11])->toBeNumeric();
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
