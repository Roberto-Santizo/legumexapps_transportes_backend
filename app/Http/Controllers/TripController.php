<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Trip\AssignTripRequest;
use App\Http\Requests\Trip\StoreTripRequest;
use App\Http\Requests\Trip\UpdateTripRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\Trip\TripResource;
use App\Interfaces\Trip\TripServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class TripController extends Controller
{
    public function index(Request $request, TripServiceInterface $tripService)
    {
        try {
            $trips = $tripService->getTrips(auth('api')->user(), $this->filters($request));

            $data = $trips instanceof LengthAwarePaginator
                ? new PaginatedResource($trips, TripResource::class)
                : TripResource::collection($trips);

            return ResponseHandler::success($data, 'Viajes obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreTripRequest $request, TripServiceInterface $tripService)
    {
        try {
            $trip = $tripService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new TripResource($trip), 'Viaje registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $trip, TripServiceInterface $tripService)
    {
        try {
            $found = $tripService->getTripById(auth('api')->user(), $trip);

            return ResponseHandler::success(new TripResource($found), 'Viaje obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateTripRequest $request, int $trip, TripServiceInterface $tripService)
    {
        try {
            /**
             * Sin el usuario: solo lo alcanza el administrador, que ve todos los viajes, así
             * que no queda ámbito que comprobar.
             */
            $updated = $tripService->update($trip, $request->validated());

            return ResponseHandler::success(new TripResource($updated), 'Viaje actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $trip, TripServiceInterface $tripService)
    {
        try {
            $deleted = $tripService->destroy($trip);

            return ResponseHandler::success(new TripResource($deleted), 'Viaje eliminado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function assign(AssignTripRequest $request, int $trip, TripServiceInterface $tripService)
    {
        try {
            $assigned = $tripService->assign(auth('api')->user(), $trip, $request->validated());

            return ResponseHandler::success(new TripResource($assigned), 'Viaje asignado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function start(int $trip, TripServiceInterface $tripService)
    {
        try {
            /** Sin FormRequest: la ruta no tiene cuerpo y la hora la pone el servidor. */
            $started = $tripService->start(auth('api')->user(), $trip);

            return ResponseHandler::success(new TripResource($started), 'Viaje iniciado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function finish(int $trip, TripServiceInterface $tripService)
    {
        try {
            $finished = $tripService->finish(auth('api')->user(), $trip);

            return ResponseHandler::success(new TripResource($finished), 'Viaje finalizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the ten listing filters, all of them optional and all of them tolerant.
     *
     * @return array<string, string|null>
     */
    private function filters(Request $request): array
    {
        $filters = [];

        foreach (['status', 'clientId', 'shippingLineId', 'locationId', 'pilotId', 'vehicleId', 'dateFrom', 'dateTo', 'search', 'limit'] as $key) {
            $filters[$key] = $this->queryString($request, $key);
        }

        return $filters;
    }

    /**
     * Read a query parameter as a string, ignoring anything that is not one.
     *
     * An array or a nested value comes back as null, so `?status[]=pending` is ignored
     * instead of blowing up inside the service.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
