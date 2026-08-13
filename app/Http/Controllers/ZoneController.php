<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Zone\StoreZoneRequest;
use App\Http\Requests\Zone\UpdateZoneRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\Zone\ZoneResource;
use App\Interfaces\Zone\ZoneServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    public function index(Request $request, ZoneServiceInterface $zoneService)
    {
        try {
            $zones = $zoneService->getZones($this->filters($request));

            $data = $zones instanceof LengthAwarePaginator
                ? new PaginatedResource($zones, ZoneResource::class)
                : ZoneResource::collection($zones);

            return ResponseHandler::success($data, 'Zonas obtenidas correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreZoneRequest $request, ZoneServiceInterface $zoneService)
    {
        try {
            $zone = $zoneService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new ZoneResource($zone), 'Zona registrada correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $zone, ZoneServiceInterface $zoneService)
    {
        try {
            $found = $zoneService->getZoneById($zone);

            return ResponseHandler::success(new ZoneResource($found), 'Zona obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateZoneRequest $request, int $zone, ZoneServiceInterface $zoneService)
    {
        try {
            $updated = $zoneService->update($zone, $request->validated());

            return ResponseHandler::success(new ZoneResource($updated), 'Zona actualizada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function toggleStatus(int $zone, ZoneServiceInterface $zoneService)
    {
        try {
            $toggled = $zoneService->toggleStatus($zone);

            return ResponseHandler::success(new ZoneResource($toggled), 'Estado de la zona actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $zone, ZoneServiceInterface $zoneService)
    {
        try {
            $deleted = $zoneService->destroy($zone);

            return ResponseHandler::success(new ZoneResource($deleted), 'Zona dada de baja correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Read the listing filters from the query string.
     *
     * Which of them are valid, and what to do with the invalid ones, is decided by the
     * service; here they are only normalized to strings, since anything else is not a
     * valid value.
     *
     * @return array{status: string|null, search: string|null, lat: string|null, lng: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'status' => $this->queryString($request, 'status'),
            'search' => $this->queryString($request, 'search'),
            'lat' => $this->queryString($request, 'lat'),
            'lng' => $this->queryString($request, 'lng'),
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
