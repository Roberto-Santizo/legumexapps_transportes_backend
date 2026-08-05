<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Vehicle\StoreVehicleRequest;
use App\Http\Requests\Vehicle\UpdateVehicleRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\Vehicle\VehicleResource;
use App\Interfaces\Vehicle\VehicleServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function index(Request $request, VehicleServiceInterface $vehicleService)
    {
        try {
            $vehicles = $vehicleService->getVehicles(auth('api')->user(), $this->filters($request));

            $data = $vehicles instanceof LengthAwarePaginator
                ? new PaginatedResource($vehicles, VehicleResource::class)
                : VehicleResource::collection($vehicles);

            return ResponseHandler::success($data, 'Vehículos obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreVehicleRequest $request, VehicleServiceInterface $vehicleService)
    {
        try {
            $vehicle = $vehicleService->createVehicle($request->validated(), auth('api')->user());

            return ResponseHandler::success(new VehicleResource($vehicle), 'Vehículo registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $vehicle, VehicleServiceInterface $vehicleService)
    {
        try {
            $found = $vehicleService->getVehicleById(auth('api')->user(), $vehicle);

            return ResponseHandler::success(new VehicleResource($found), 'Vehículo obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateVehicleRequest $request, int $vehicle, VehicleServiceInterface $vehicleService)
    {
        try {
            $updated = $vehicleService->updateVehicle($request->validated(), $vehicle, auth('api')->user());

            return ResponseHandler::success(new VehicleResource($updated), 'Vehículo actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $vehicle, VehicleServiceInterface $vehicleService)
    {
        try {
            $deactivated = $vehicleService->deleteVehicle($vehicle, auth('api')->user());

            return ResponseHandler::success(new VehicleResource($deactivated), 'Vehículo desactivado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Read the listing filters from the query string.
     *
     * Which of them are valid, and what to do with the invalid ones, is decided
     * by the service; here they are only normalized to strings, since anything
     * else is not a valid value.
     *
     * @return array{status: string|null, carrierId: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'status' => $this->queryString($request, 'status'),
            'carrierId' => $this->queryString($request, 'carrierId'),
            'limit' => $this->queryString($request, 'limit'),
        ];
    }

    /**
     * Read a query string parameter, discarding anything that is not a string.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
