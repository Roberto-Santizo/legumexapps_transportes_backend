<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\AccessoryCharacteristic\IndexAccessoryCharacteristicRequest;
use App\Http\Requests\AccessoryCharacteristic\StoreAccessoryCharacteristicRequest;
use App\Http\Requests\AccessoryCharacteristic\UpdateAccessoryCharacteristicRequest;
use App\Http\Resources\AccessoryCharacteristic\AccessoryCharacteristicResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\AccessoryCharacteristic\AccessoryCharacteristicServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class AccessoryCharacteristicController extends Controller
{
    public function index(IndexAccessoryCharacteristicRequest $request, AccessoryCharacteristicServiceInterface $accessoryCharacteristicService)
    {
        try {
            $result = $accessoryCharacteristicService->getAccessoryCharacteristics($this->filters($request));

            $characteristics = $result['characteristics'];

            $data = $characteristics instanceof LengthAwarePaginator
                ? (new PaginatedResource($characteristics, AccessoryCharacteristicResource::class))->resolve()
                /**
                 * Sin envolver en ['data' => ...]: ResponseHandler solo aplana esa clave
                 * cuando el array trae más de una, así que envolverla anidaría data
                 * dentro de data. VehicleExpenseController se salva porque añade
                 * totalAmount, que aquí no existe.
                 */
                : AccessoryCharacteristicResource::collection($characteristics);

            return ResponseHandler::success($data, 'Características obtenidas correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreAccessoryCharacteristicRequest $request, AccessoryCharacteristicServiceInterface $accessoryCharacteristicService)
    {
        try {
            $characteristic = $accessoryCharacteristicService->createAccessoryCharacteristic($request->validated(), auth('api')->user());

            return ResponseHandler::success(new AccessoryCharacteristicResource($characteristic), 'Característica registrada correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $accessoryCharacteristic, AccessoryCharacteristicServiceInterface $accessoryCharacteristicService)
    {
        try {
            $found = $accessoryCharacteristicService->getAccessoryCharacteristicById($accessoryCharacteristic);

            return ResponseHandler::success(new AccessoryCharacteristicResource($found), 'Característica obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateAccessoryCharacteristicRequest $request, int $accessoryCharacteristic, AccessoryCharacteristicServiceInterface $accessoryCharacteristicService)
    {
        try {
            $updated = $accessoryCharacteristicService->updateAccessoryCharacteristic($request->validated(), $accessoryCharacteristic);

            return ResponseHandler::success(new AccessoryCharacteristicResource($updated), 'Característica actualizada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $accessoryCharacteristic, AccessoryCharacteristicServiceInterface $accessoryCharacteristicService)
    {
        try {
            $deleted = $accessoryCharacteristicService->deleteAccessoryCharacteristic($accessoryCharacteristic);

            return ResponseHandler::success(new AccessoryCharacteristicResource($deleted), 'Característica eliminada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the listing filters from the query string.
     *
     * `accessoryId` is the only filter of the domain: there is no search, no status
     * and no configurable sorting.
     *
     * @return array{accessoryId: int, limit: string|null}
     */
    private function filters(IndexAccessoryCharacteristicRequest $request): array
    {
        return [
            'accessoryId' => (int) $request->validated('accessoryId'),
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
