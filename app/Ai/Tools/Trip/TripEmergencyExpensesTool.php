<?php

namespace App\Ai\Tools\Trip;

use App\Http\Resources\TripEmergencyExpense\TripEmergencyExpenseResource;
use App\Interfaces\TripEmergencyExpense\TripEmergencyExpenseServiceInterface;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * `GET /api/trips/{trip}/emergency-expenses` as a tool: the unforeseen expenses of one
 * trip (SPEC 39), kept apart from the allowances of `trip_expenses`.
 */
class TripEmergencyExpensesTool extends TripNestedTool
{
    public function __construct(
        User $user,
        private readonly TripEmergencyExpenseServiceInterface $emergencyExpenses,
    ) {
        parent::__construct($user);
    }

    public function name(): string
    {
        return 'trip_emergency_expenses';
    }

    public function description(): string
    {
        return 'Los gastos emergentes de un viaje: imprevistos pagados en carretera (una llanta pinchada, una grúa), registrados por la empresa transportista, del más antiguo al más reciente. NO son viáticos —el dinero entregado al piloto está en trip_expenses—. Por gasto: monto en quetzales, descripción de lo que pasó, enlace al comprobante (receiptUrl, null si no hay) y su tipo, quién lo registró, cuándo (createdAt) y su última corrección (updatedAt). No tienen confirmación: totalAmount suma todos, y es el mismo número que totalEmergencyExpensesAmount en el detalle del viaje. Un viaje sin gastos emergentes devuelve una lista vacía.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tripId' => $this->tripIdSchema($schema),
            'limit' => $this->limitSchema($schema, 10, self::DEFAULT_LIMIT, 'gastos emergentes'),
        ];
    }

    protected function query(array $filters): array
    {
        $result = $this->emergencyExpenses->getTripEmergencyExpenses($this->user, $filters['tripId'], ['limit' => $filters['limit']]);

        /** @var LengthAwarePaginator $expenses */
        $expenses = $result['emergencyExpenses'];

        return [
            'totalAmount' => $result['totalAmount'],
            ...$this->pageOf($expenses),
            'emergencyExpenses' => TripEmergencyExpenseResource::collection($expenses->getCollection())->resolve(),
        ];
    }
}
