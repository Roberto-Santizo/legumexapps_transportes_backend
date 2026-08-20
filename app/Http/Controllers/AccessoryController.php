<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Accessory\StoreAccessoryRequest;
use App\Http\Requests\Accessory\UpdateAccessoryRequest;
use App\Http\Resources\Accessory\AccessoryResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\Accessory\AccessoryServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class AccessoryController extends Controller
{
    public function index(Request $request, AccessoryServiceInterface $accessoryService)
    {
        try {
            $accessories = $accessoryService->getAccessories($this->filters($request));

            $data = $accessories instanceof LengthAwarePaginator
                ? new PaginatedResource($accessories, AccessoryResource::class)
                : AccessoryResource::collection($accessories);

            return ResponseHandler::success($data, 'Accesorios obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreAccessoryRequest $request, AccessoryServiceInterface $accessoryService)
    {
        try {
            $accessory = $accessoryService->createAccessory($request->validated(), auth('api')->user());

            return ResponseHandler::success(new AccessoryResource($accessory), 'Accesorio registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $accessory, AccessoryServiceInterface $accessoryService)
    {
        try {
            $found = $accessoryService->getAccessoryById($accessory);

            return ResponseHandler::success(new AccessoryResource($found), 'Accesorio obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateAccessoryRequest $request, int $accessory, AccessoryServiceInterface $accessoryService)
    {
        try {
            $updated = $accessoryService->updateAccessory($request->validated(), $accessory);

            return ResponseHandler::success(new AccessoryResource($updated), 'Accesorio actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $accessory, AccessoryServiceInterface $accessoryService)
    {
        try {
            $deleted = $accessoryService->deleteAccessory($accessory);

            return ResponseHandler::success(new AccessoryResource($deleted), 'Accesorio dado de baja correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the listing filters from the query string.
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
     * Read a query param only when it arrived as a string.
     *
     * An array param (`?status[]=active`) would blow up the tolerant filters, which
     * expect a scalar; treating it as absent keeps the listing answering 200.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
