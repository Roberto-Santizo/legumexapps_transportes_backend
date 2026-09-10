<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\TripFuel\StoreTripFuelRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\TripFuel\TripFuelResource;
use App\Interfaces\TripFuel\TripFuelServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class TripFuelController extends Controller
{
    public function index(Request $request, int $trip, TripFuelServiceInterface $tripFuelService)
    {
        try {
            $result = $tripFuelService->getTripFuels(
                auth('api')->user(),
                $trip,
                ['limit' => $this->queryString($request, 'limit')],
            );

            $fuels = $result['fuels'];

            $data = $fuels instanceof LengthAwarePaginator
                ? (new PaginatedResource($fuels, TripFuelResource::class))->resolve()
                : ['data' => TripFuelResource::collection($fuels)->resolve()];

            /** El acumulado viaja en la raíz del sobre junto a la metadata de paginación, y también sin ella: es dato de negocio, no del paginador. */
            $data['totalGallons'] = $result['totalGallons'];

            return ResponseHandler::success($data, 'Cargas de combustible obtenidas correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreTripFuelRequest $request, int $trip, TripFuelServiceInterface $tripFuelService)
    {
        try {
            $fuel = $tripFuelService->create(auth('api')->user(), $trip, $request->validated());

            return ResponseHandler::success(
                new TripFuelResource($fuel),
                'Carga de combustible registrada correctamente',
                201,
            );
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Confirm one fuel load on behalf of the assigned pilot.
     *
     * Always **200**, whether the date was just written or it was already there: the
     * confirmation is a fact that happened once, and a retry from a phone on a bad
     * network must not see an error.
     */
    public function confirm(int $tripFuel, TripFuelServiceInterface $tripFuelService)
    {
        try {
            $fuel = $tripFuelService->confirm(auth('api')->user(), $tripFuel);

            return ResponseHandler::success(
                new TripFuelResource($fuel),
                'Carga de combustible confirmada correctamente',
                200,
            );
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Read a query parameter as a string, ignoring anything that is not one.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
