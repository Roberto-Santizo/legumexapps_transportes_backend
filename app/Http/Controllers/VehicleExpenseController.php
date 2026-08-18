<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\VehicleExpense\IndexVehicleExpenseRequest;
use App\Http\Requests\VehicleExpense\StoreVehicleExpenseRequest;
use App\Http\Requests\VehicleExpense\UpdateVehicleExpenseRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\VehicleExpense\VehicleExpenseResource;
use App\Interfaces\VehicleExpense\VehicleExpenseServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class VehicleExpenseController extends Controller
{
    public function index(IndexVehicleExpenseRequest $request, VehicleExpenseServiceInterface $vehicleExpenseService)
    {
        try {
            $result = $vehicleExpenseService->getVehicleExpenses(auth('api')->user(), $this->filters($request));

            $expenses = $result['expenses'];

            $data = $expenses instanceof LengthAwarePaginator
                ? (new PaginatedResource($expenses, VehicleExpenseResource::class))->resolve()
                : ['data' => VehicleExpenseResource::collection($expenses)->resolve()];

            /** El acumulado viaja en la raíz del sobre junto a la metadata de paginación, y también sin ella: es dato de negocio, no del paginador. */
            $data['totalAmount'] = $result['totalAmount'];

            return ResponseHandler::success($data, 'Gastos obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreVehicleExpenseRequest $request, VehicleExpenseServiceInterface $vehicleExpenseService)
    {
        try {
            $expense = $vehicleExpenseService->createVehicleExpense($request->validated(), auth('api')->user());

            return ResponseHandler::success(new VehicleExpenseResource($expense), 'Gasto registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $vehicleExpense, VehicleExpenseServiceInterface $vehicleExpenseService)
    {
        try {
            $found = $vehicleExpenseService->getVehicleExpenseById(auth('api')->user(), $vehicleExpense);

            return ResponseHandler::success(new VehicleExpenseResource($found), 'Gasto obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateVehicleExpenseRequest $request, int $vehicleExpense, VehicleExpenseServiceInterface $vehicleExpenseService)
    {
        try {
            $updated = $vehicleExpenseService->updateVehicleExpense($request->validated(), $vehicleExpense, auth('api')->user());

            return ResponseHandler::success(new VehicleExpenseResource($updated), 'Gasto actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $vehicleExpense, VehicleExpenseServiceInterface $vehicleExpenseService)
    {
        try {
            $deleted = $vehicleExpenseService->deleteVehicleExpense($vehicleExpense, auth('api')->user());

            return ResponseHandler::success(new VehicleExpenseResource($deleted), 'Gasto eliminado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Listing filters, read from the query string.
     *
     * `vehicleId` comes from the validated payload because it is the only
     * required one; the rest are tolerant and travel raw, so the service can
     * ignore whatever it cannot use.
     *
     * @return array{vehicleId: int, category: string|null, nature: string|null, dateFrom: string|null, dateTo: string|null, limit: string|null}
     */
    private function filters(IndexVehicleExpenseRequest $request): array
    {
        return [
            'vehicleId' => (int) $request->validated('vehicleId'),
            'category' => $this->queryString($request, 'category'),
            'nature' => $this->queryString($request, 'nature'),
            'dateFrom' => $this->queryString($request, 'dateFrom'),
            'dateTo' => $this->queryString($request, 'dateTo'),
            'limit' => $this->queryString($request, 'limit'),
        ];
    }

    /**
     * Read a query parameter, discarding anything that is not a plain string.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
