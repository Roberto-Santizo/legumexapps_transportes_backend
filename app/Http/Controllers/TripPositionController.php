<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\TripPosition\StoreTripPositionRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\TripPosition\TripPositionResource;
use App\Interfaces\TripPosition\TripPositionServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class TripPositionController extends Controller
{
    public function index(Request $request, int $trip, TripPositionServiceInterface $tripPositionService)
    {
        try {
            $positions = $tripPositionService->getPositions(
                auth('api')->user(),
                $trip,
                ['limit' => $this->queryString($request, 'limit')],
            );

            $data = $positions instanceof LengthAwarePaginator
                ? new PaginatedResource($positions, TripPositionResource::class)
                : TripPositionResource::collection($positions);

            return ResponseHandler::success($data, 'Posiciones obtenidas correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Record one point of the trip's track.
     *
     * Answers **201** when the point was written and **200** when the 15 second floor
     * discarded it and the previous point is being handed back: the pilot's app can
     * tell one from the other by the status alone, without comparing coordinates.
     */
    public function store(StoreTripPositionRequest $request, int $trip, TripPositionServiceInterface $tripPositionService)
    {
        try {
            $user = auth('api')->user();

            $position = $tripPositionService->create($user, $trip, $request->validated());

            /** Un punto recién escrito no puede haber sido creado antes de esta petición. */
            $wasRecorded = $position->wasRecentlyCreated;

            return ResponseHandler::success(
                new TripPositionResource($position),
                $wasRecorded ? 'Posición registrada correctamente' : 'Posición recibida correctamente',
                $wasRecorded ? 201 : 200,
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
