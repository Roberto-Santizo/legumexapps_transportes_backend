<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Location\StoreLocationRequest;
use App\Http\Requests\Location\UpdateLocationRequest;
use App\Http\Resources\Location\LocationResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\Location\LocationServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function index(Request $request, LocationServiceInterface $locationService)
    {
        try {
            $locations = $locationService->getLocations($this->filters($request));

            $data = $locations instanceof LengthAwarePaginator
                ? new PaginatedResource($locations, LocationResource::class)
                : LocationResource::collection($locations);

            return ResponseHandler::success($data, 'Destinos obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreLocationRequest $request, LocationServiceInterface $locationService)
    {
        try {
            $location = $locationService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new LocationResource($location), 'Destino registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $location, LocationServiceInterface $locationService)
    {
        try {
            $found = $locationService->getLocationById($location);

            return ResponseHandler::success(new LocationResource($found), 'Destino obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateLocationRequest $request, int $location, LocationServiceInterface $locationService)
    {
        try {
            $updated = $locationService->update($location, $request->validated());

            return ResponseHandler::success(new LocationResource($updated), 'Destino actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function toggleStatus(int $location, LocationServiceInterface $locationService)
    {
        try {
            $toggled = $locationService->toggleStatus($location);

            return ResponseHandler::success(new LocationResource($toggled), 'Estado del destino actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $location, LocationServiceInterface $locationService)
    {
        try {
            $deleted = $locationService->destroy($location);

            return ResponseHandler::success(new LocationResource($deleted), 'Destino dado de baja correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the listing filters the service understands.
     *
     * @return array{status: string|null, search: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'status' => $this->queryString($request, 'status'),
            'search' => $this->queryString($request, 'search'),
            'limit' => $this->queryString($request, 'limit'),
        ];
    }

    /**
     * Read a query string parameter, keeping only actual strings.
     *
     * An array or a missing key degrades to null, which every filter treats as absent.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
