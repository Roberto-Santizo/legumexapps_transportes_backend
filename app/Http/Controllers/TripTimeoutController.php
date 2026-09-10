<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\TripTimeout\TripTimeoutResource;
use App\Interfaces\TripTimeout\TripTimeoutServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class TripTimeoutController extends Controller
{
    /**
     * List the stops detected on the trip's track.
     *
     * The only method of the controller: nothing creates, edits or deletes a stop by
     * request. They are born as a side effect of `POST /api/trips/{trip}/positions` and
     * closed either by the point that proves the truck moved or by the trip's finish.
     */
    public function index(Request $request, int $trip, TripTimeoutServiceInterface $tripTimeoutService)
    {
        try {
            $timeouts = $tripTimeoutService->getTimeouts(
                auth('api')->user(),
                $trip,
                ['limit' => $this->queryString($request, 'limit')],
            );

            $data = $timeouts instanceof LengthAwarePaginator
                ? new PaginatedResource($timeouts, TripTimeoutResource::class)
                : TripTimeoutResource::collection($timeouts);

            return ResponseHandler::success($data, 'Paradas obtenidas correctamente', 200);
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
