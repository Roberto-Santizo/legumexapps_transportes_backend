<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\FuelPrice\CurrentFuelPriceRequest;
use App\Http\Requests\FuelPrice\StoreFuelPriceRequest;
use App\Http\Requests\FuelPrice\UpdateFuelPriceRequest;
use App\Http\Resources\FuelPrice\FuelPriceResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\FuelPrice\FuelPriceServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class FuelPriceController extends Controller
{
    public function index(Request $request, FuelPriceServiceInterface $fuelPriceService)
    {
        try {
            $fuelPrices = $fuelPriceService->getFuelPrices($this->filters($request));

            $data = $fuelPrices instanceof LengthAwarePaginator
                ? new PaginatedResource($fuelPrices, FuelPriceResource::class)
                : FuelPriceResource::collection($fuelPrices);

            return ResponseHandler::success($data, 'Precios de combustible obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function current(CurrentFuelPriceRequest $request, FuelPriceServiceInterface $fuelPriceService)
    {
        try {
            $fuelPrice = $fuelPriceService->getCurrentByType($request->validated()['fuelType']);

            return ResponseHandler::success(new FuelPriceResource($fuelPrice), 'Precio vigente obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreFuelPriceRequest $request, FuelPriceServiceInterface $fuelPriceService)
    {
        try {
            $fuelPrice = $fuelPriceService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new FuelPriceResource($fuelPrice), 'Precio de combustible registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $fuelPrice, FuelPriceServiceInterface $fuelPriceService)
    {
        try {
            $found = $fuelPriceService->getFuelPriceById($fuelPrice);

            return ResponseHandler::success(new FuelPriceResource($found), 'Precio de combustible obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateFuelPriceRequest $request, int $fuelPrice, FuelPriceServiceInterface $fuelPriceService)
    {
        try {
            $updated = $fuelPriceService->update($fuelPrice, $request->validated());

            return ResponseHandler::success(new FuelPriceResource($updated), 'Precio de combustible actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function deactivate(int $fuelPrice, FuelPriceServiceInterface $fuelPriceService)
    {
        try {
            $deactivated = $fuelPriceService->deactivate($fuelPrice);

            return ResponseHandler::success(new FuelPriceResource($deactivated), 'Precio de combustible desactivado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $fuelPrice, FuelPriceServiceInterface $fuelPriceService)
    {
        try {
            $deleted = $fuelPriceService->destroy($fuelPrice);

            return ResponseHandler::success(new FuelPriceResource($deleted), 'Precio de combustible eliminado correctamente', 200);
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
     * @return array{fuelType: string|null, status: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'fuelType' => $this->queryString($request, 'fuelType'),
            'status' => $this->queryString($request, 'status'),
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
