<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\DeparturePoint\StoreDeparturePointRequest;
use App\Http\Requests\DeparturePoint\UpdateDeparturePointRequest;
use App\Http\Resources\DeparturePoint\DeparturePointResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\DeparturePoint\DeparturePointServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class DeparturePointController extends Controller
{
    public function index(Request $request, DeparturePointServiceInterface $departurePointService)
    {
        try {
            $departurePoints = $departurePointService->getDeparturePoints($this->filters($request));

            $data = $departurePoints instanceof LengthAwarePaginator
                ? new PaginatedResource($departurePoints, DeparturePointResource::class)
                : DeparturePointResource::collection($departurePoints);

            return ResponseHandler::success($data, 'Puntos de partida obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreDeparturePointRequest $request, DeparturePointServiceInterface $departurePointService)
    {
        try {
            $departurePoint = $departurePointService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new DeparturePointResource($departurePoint), 'Punto de partida registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $departurePoint, DeparturePointServiceInterface $departurePointService)
    {
        try {
            $found = $departurePointService->getDeparturePointById($departurePoint);

            return ResponseHandler::success(new DeparturePointResource($found), 'Punto de partida obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateDeparturePointRequest $request, int $departurePoint, DeparturePointServiceInterface $departurePointService)
    {
        try {
            $updated = $departurePointService->update($departurePoint, $request->validated());

            return ResponseHandler::success(new DeparturePointResource($updated), 'Punto de partida actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function toggleStatus(int $departurePoint, DeparturePointServiceInterface $departurePointService)
    {
        try {
            $toggled = $departurePointService->toggleStatus($departurePoint);

            return ResponseHandler::success(new DeparturePointResource($toggled), 'Estado del punto de partida actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $departurePoint, DeparturePointServiceInterface $departurePointService)
    {
        try {
            $deleted = $departurePointService->destroy($departurePoint);

            return ResponseHandler::success(new DeparturePointResource($deleted), 'Punto de partida dado de baja correctamente', 200);
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
