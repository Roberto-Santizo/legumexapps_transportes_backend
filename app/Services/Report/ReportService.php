<?php

namespace App\Services\Report;

use App\Enums\TripStatus;
use App\Enums\VehicleExpenseNature;
use App\Http\Resources\Trip\TripListResource;
use App\Http\Resources\VehicleExpense\VehicleExpenseResource;
use App\Interfaces\Report\ReportServiceInterface;
use App\Interfaces\Report\SpreadsheetWriterInterface;
use App\Interfaces\Storage\FileStorageServiceInterface;
use App\Interfaces\Trip\TripServiceInterface;
use App\Interfaces\VehicleExpense\VehicleExpenseServiceInterface;
use App\Models\User;
use Illuminate\Support\Collection;
use Override;

/**
 * Spreadsheet exports of listings the API already serves as JSON.
 *
 * Nothing here queries the database: each export calls the same service method the
 * endpoint calls, without `limit`, so the scope by role and the tolerant filters
 * apply untouched, and shapes the rows through the same Resource the endpoint uses.
 * The only additions are the cap on rows, the Spanish headers and the translation of
 * the enum values the Resources leave raw.
 *
 * The file goes to the default disk through `FileStorageServiceInterface`, under a
 * uuid key: the human file name is returned for display, not used as the key.
 */
final class ReportService implements ReportServiceInterface
{
    /**
     * Most rows a single export keeps: the whole turn of the assistant runs inside
     * one request, and beyond this the writer would eat the budget on its own.
     */
    public const int MAX_ROWS = 5000;

    /**
     * Directory the reports are stored under, relative to the disk root.
     */
    private const string REPORT_DIRECTORY = 'reports';

    /**
     * Extension of every file this service produces.
     */
    private const string EXTENSION = 'xlsx';

    /**
     * Timezone the timestamp in the file name is expressed in.
     */
    private const string TIMEZONE = 'America/Guatemala';

    /**
     * Format of the timestamp in the file name.
     */
    private const string FILE_NAME_TIMESTAMP = 'Y-m-d-His';

    /**
     * Headers of the trips sheet, in the order of `TripListResource`.
     *
     * @var list<string>
     */
    private const array TRIP_HEADERS = [
        'Id', 'Orden', 'Estado', 'Naviera', 'Punto de partida', 'Puerto', 'Contenedor',
        'Fecha recolección', 'Fecha embarque', 'Inicio', 'Fin', 'Km estimados',
        'Horas estimadas', 'Observaciones', 'Piloto', 'Placa', 'Registrado por',
    ];

    /**
     * Headers of the vehicle expenses sheet, in the order of `VehicleExpenseResource`
     * minus `vehicleId` (every row shares it) and `invoiceType` (implied by the URL).
     *
     * @var list<string>
     */
    private const array VEHICLE_EXPENSE_HEADERS = [
        'Id', 'Categoría', 'Naturaleza', 'Monto (Q)', 'Fecha', 'Descripción',
        'Facturado', 'Factura', 'Registrado por', 'Creado',
    ];

    /**
     * @var array<string, string>
     */
    private const array STATUS_LABELS = [
        TripStatus::Pending->value => 'Pendiente',
        TripStatus::InRoute->value => 'En ruta',
        TripStatus::Finished->value => 'Finalizado',
    ];

    /**
     * @var array<string, string>
     */
    private const array NATURE_LABELS = [
        VehicleExpenseNature::Preventive->value => 'Preventivo',
        VehicleExpenseNature::Corrective->value => 'Correctivo',
    ];

    /**
     * `$maxRows` is a parameter only so a test can prove the cap without inserting
     * five thousand rows; production always resolves the default.
     */
    public function __construct(
        private readonly TripServiceInterface $trips,
        private readonly VehicleExpenseServiceInterface $vehicleExpenses,
        private readonly SpreadsheetWriterInterface $writer,
        private readonly FileStorageServiceInterface $storage,
        private readonly int $maxRows = self::MAX_ROWS,
    ) {}

    #[Override]
    public function exportTrips(User $user, array $filters): array
    {
        /** @var Collection $trips */
        $trips = $this->trips->getTrips($user, $this->withoutLimit($filters));

        $rows = array_map(
            static fn (array $trip): array => [
                $trip['id'],
                $trip['order'],
                self::STATUS_LABELS[$trip['status']] ?? $trip['status'],
                $trip['shippingLineName'],
                $trip['departurePointName'],
                $trip['locationName'],
                $trip['container'],
                $trip['recolectionDate'],
                $trip['shipDate'],
                $trip['startDate'],
                $trip['endDate'],
                self::toNumber($trip['estimatedKilometers']),
                self::toNumber($trip['estimatedHours']),
                $trip['observations'],
                $trip['pilotName'],
                $trip['vehiclePlate'],
                $trip['registeredByName'],
            ],
            TripListResource::collection($trips->take($this->maxRows))->resolve(),
        );

        return $this->publish('viajes', self::TRIP_HEADERS, $rows, $trips->count());
    }

    #[Override]
    public function exportVehicleExpenses(User $user, array $filters): array
    {
        $result = $this->vehicleExpenses->getVehicleExpenses($user, $this->withoutLimit($filters));

        /** @var Collection $expenses */
        $expenses = $result['expenses'];

        $rows = array_map(
            static fn (array $expense): array => [
                $expense['id'],
                $expense['category'],
                self::NATURE_LABELS[$expense['nature']] ?? $expense['nature'],
                self::toNumber($expense['amount']),
                $expense['expenseDate'],
                $expense['description'],
                $expense['isInvoiced'] ? 'Sí' : 'No',
                $expense['invoiceUrl'],
                $expense['registeredBy'],
                $expense['createdAt'],
            ],
            VehicleExpenseResource::collection($expenses->take($this->maxRows))->resolve(),
        );

        $prefix = 'gastos-vehiculo-'.$filters['vehicleId'];

        return [
            ...$this->publish($prefix, self::VEHICLE_EXPENSE_HEADERS, $rows, $expenses->count()),
            'totalAmount' => $result['totalAmount'],
        ];
    }

    /**
     * Write the sheet, store it and describe the result.
     *
     * @param  list<string>  $headers
     * @param  list<list<scalar|null>>  $rows
     * @return array{fileName: string, url: string, rows: int, total: int, truncated: bool}
     */
    private function publish(string $prefix, array $headers, array $rows, int $total): array
    {
        $key = $this->storage->store(
            $this->writer->write($headers, $rows),
            self::REPORT_DIRECTORY,
            self::EXTENSION,
        );

        $timestamp = now(self::TIMEZONE)->format(self::FILE_NAME_TIMESTAMP);

        return [
            'fileName' => $prefix.'-'.$timestamp.'.'.self::EXTENSION,
            'url' => $this->storage->url($key),
            'rows' => count($rows),
            'total' => $total,
            'truncated' => $total > count($rows),
        ];
    }

    /**
     * The services paginate when `limit` is numeric; an export wants the whole set.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function withoutLimit(array $filters): array
    {
        unset($filters['limit']);

        return $filters;
    }

    /**
     * Money and measures leave the Resources as strings with two decimals; the sheet
     * wants a number Excel can add up.
     */
    private static function toNumber(?string $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
