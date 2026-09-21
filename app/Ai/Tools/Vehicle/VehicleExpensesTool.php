<?php

namespace App\Ai\Tools\Vehicle;

use App\Ai\Tools\AssistantTool;
use App\Enums\VehicleExpenseCategory;
use App\Enums\VehicleExpenseNature;
use App\Http\Resources\VehicleExpense\VehicleExpenseResource;
use App\Interfaces\VehicleExpense\VehicleExpenseServiceInterface;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Laravel\Ai\Tools\Request;

/**
 * `GET /api/vehicle-expenses?vehicleId=` as a tool: the maintenance expenses of one vehicle.
 *
 * The scope of SPEC 14 already matches the dashboard's —`administrator` and `manager`
 * reach every company, a `carrier` only its own—, so the service is wrapped as is.
 * Always paginated, like every listing of the assistant, and without the invoice URL:
 * the model has nothing to do with a link.
 */
class VehicleExpensesTool extends AssistantTool
{
    protected const array FILTERS = ['category', 'nature', 'dateFrom', 'dateTo', 'isInvoiced', 'limit'];

    /** Rows returned when the model does not ask for a size. */
    private const int DEFAULT_LIMIT = 25;

    public function __construct(
        User $user,
        private readonly VehicleExpenseServiceInterface $expenses,
    ) {
        parent::__construct($user);
    }

    public function name(): string
    {
        return 'vehicle_expenses';
    }

    public function description(): string
    {
        return 'Los gastos de mantenimiento de un vehículo, del más reciente al más antiguo por fecha del gasto. Por gasto: categoría, naturaleza (preventive, corrective), monto en quetzales, fecha, descripción, si tiene factura y quién lo registró. totalAmount suma todos los gastos que cumplen los filtros, no solo los devueltos. El estado del vehículo no importa: uno inactivo lista sus gastos igual. Un vehículo sin gastos devuelve una lista vacía. Requiere el id del vehículo; si solo tienes la placa, resuélvelo antes con vehicle.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'vehicleId' => $schema->integer()->required()->description('Id del vehículo. Si solo conoces la placa, obtén el id con vehicle.'),
            'category' => $schema->string()
                ->enum(array_column(VehicleExpenseCategory::cases(), 'value'))
                ->description('Categoría del gasto. Un valor fuera de la lista se ignora.'),
            'nature' => $schema->string()
                ->enum(array_column(VehicleExpenseNature::cases(), 'value'))
                ->description('Naturaleza del gasto: preventive o corrective. Un valor fuera de la lista se ignora.'),
            ...$this->dateRangeSchema($schema, 'la fecha del gasto'),
            'isInvoiced' => $schema->boolean()
                ->description('true: solo gastos con factura; false: solo los que no la tienen. Sin él, todos.'),
            'limit' => $this->limitSchema($schema, 10, self::DEFAULT_LIMIT, 'gastos'),
        ];
    }

    protected function arguments(Request $request): array
    {
        $arguments = $this->filters($request);
        $arguments['limit'] ??= (string) self::DEFAULT_LIMIT;
        $arguments['vehicleId'] = $this->resourceId($request, 'vehicleId');

        return $arguments;
    }

    protected function query(array $filters): array
    {
        $result = $this->expenses->getVehicleExpenses($this->user, $filters);

        /** @var LengthAwarePaginator $expenses */
        $expenses = $result['expenses'];

        return [
            'totalAmount' => $result['totalAmount'],
            'total' => $expenses->total(),
            'returned' => $expenses->count(),
            'expenses' => array_map(
                static fn (array $expense): array => Arr::except($expense, ['invoiceUrl']),
                VehicleExpenseResource::collection($expenses->getCollection())->resolve(),
            ),
        ];
    }
}
