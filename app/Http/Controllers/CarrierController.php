<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Carrier\JoinCarrierRequest;
use App\Http\Requests\Carrier\StoreCarrierRequest;
use App\Http\Requests\Carrier\UpdateCarrierRequest;
use App\Http\Resources\Carrier\CarrierPilotResource;
use App\Http\Resources\Carrier\CarrierResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\Carrier\CarrierServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class CarrierController extends Controller
{
    public function index(Request $request, CarrierServiceInterface $carrierService)
    {
        try {
            $carriers = $carrierService->getCarriers($this->limit($request));

            $data = $carriers instanceof LengthAwarePaginator
                ? new PaginatedResource($carriers, CarrierResource::class)
                : CarrierResource::collection($carriers);

            return ResponseHandler::success($data, 'Transportistas obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreCarrierRequest $request, CarrierServiceInterface $carrierService)
    {
        try {
            $carrier = $carrierService->createCarrier($request->validated(), auth('api')->user());

            return ResponseHandler::success(new CarrierResource($carrier), 'Empresa transportista creada correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $carrier, CarrierServiceInterface $carrierService)
    {
        try {
            $found = $carrierService->getCarrierById($carrier);

            return ResponseHandler::success(new CarrierResource($found), 'Transportista obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateCarrierRequest $request, int $carrier, CarrierServiceInterface $carrierService)
    {
        try {
            $updated = $carrierService->updateCarrier($request->validated(), $carrier, auth('api')->user());

            return ResponseHandler::success(new CarrierResource($updated), 'Transportista actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $carrier, CarrierServiceInterface $carrierService)
    {
        try {
            $carrierService->deleteCarrier($carrier);

            return ResponseHandler::success(null, 'Transportista eliminado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function join(JoinCarrierRequest $request, CarrierServiceInterface $carrierService)
    {
        try {
            $carrierService->joinCarrier($request->validated(), auth('api')->user());

            return ResponseHandler::success(null, 'Te has vinculado a la empresa transportista correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function me(Request $request, CarrierServiceInterface $carrierService)
    {
        try {
            $carrier = $carrierService->getMyCarrier(auth('api')->user());

            return ResponseHandler::success(new CarrierResource($carrier), 'Empresa transportista obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function pilots(Request $request, CarrierServiceInterface $carrierService)
    {
        try {
            $pilots = $carrierService->getMyPilots(auth('api')->user(), $this->limit($request));

            $data = $pilots instanceof LengthAwarePaginator
                ? new PaginatedResource($pilots, CarrierPilotResource::class)
                : CarrierPilotResource::collection($pilots);

            return ResponseHandler::success($data, 'Pilotos obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Read the page size requested on the query string.
     *
     * Whether it paginates or not is decided by the service; here it is only
     * normalized to a string, since anything else is not a valid limit.
     */
    private function limit(Request $request): ?string
    {
        $limit = $request->query('limit');

        return is_string($limit) ? $limit : null;
    }
}
