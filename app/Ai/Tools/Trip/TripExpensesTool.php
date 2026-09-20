<?php

namespace App\Ai\Tools\Trip;

use App\Http\Resources\TripExpense\TripExpenseResource;
use App\Interfaces\TripExpense\TripExpenseServiceInterface;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * `GET /api/trips/{trip}/expenses` as a tool: the travel allowances of one trip.
 */
class TripExpensesTool extends TripNestedTool
{
    public function __construct(
        User $user,
        private readonly TripExpenseServiceInterface $expenses,
    ) {
        parent::__construct($user);
    }

    public function name(): string
    {
        return 'trip_expenses';
    }

    public function description(): string
    {
        return 'Los viáticos entregados al piloto en un viaje, del más antiguo al más reciente. Por viático: monto en quetzales, descripción (puede ser null), si el piloto confirmó haberlo recibido (isConfirmed), cuándo (receivedAt, null si no), quién lo confirmó y quién lo registró. totalAmount suma solo los confirmados, que es el mismo número que totalExpensesAmount en el detalle del viaje. Un viaje sin viáticos devuelve una lista vacía.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tripId' => $this->tripIdSchema($schema),
            'limit' => $this->limitSchema($schema, 10, self::DEFAULT_LIMIT, 'viáticos'),
        ];
    }

    protected function query(array $filters): array
    {
        $result = $this->expenses->getTripExpenses($this->user, $filters['tripId'], ['limit' => $filters['limit']]);

        /** @var LengthAwarePaginator $expenses */
        $expenses = $result['expenses'];

        return [
            'totalAmount' => $result['totalAmount'],
            ...$this->pageOf($expenses),
            'expenses' => TripExpenseResource::collection($expenses->getCollection())->resolve(),
        ];
    }
}
