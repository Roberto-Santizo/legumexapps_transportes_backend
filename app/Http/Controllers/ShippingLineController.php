<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\ShippingLine\StoreShippingLineRequest;
use App\Http\Requests\ShippingLine\UpdateShippingLineRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\ShippingLine\ShippingLineResource;
use App\Interfaces\ShippingLine\ShippingLineServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class ShippingLineController extends Controller
{
    public function index(Request $request, ShippingLineServiceInterface $shippingLineService)
    {
        try {
            $shippingLines = $shippingLineService->getShippingLines($this->filters($request));

            $data = $shippingLines instanceof LengthAwarePaginator
                ? new PaginatedResource($shippingLines, ShippingLineResource::class)
                : ShippingLineResource::collection($shippingLines);

            return ResponseHandler::success($data, 'Navieras obtenidas correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreShippingLineRequest $request, ShippingLineServiceInterface $shippingLineService)
    {
        try {
            $shippingLine = $shippingLineService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new ShippingLineResource($shippingLine), 'Naviera registrada correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $shippingLine, ShippingLineServiceInterface $shippingLineService)
    {
        try {
            $found = $shippingLineService->getShippingLineById($shippingLine);

            return ResponseHandler::success(new ShippingLineResource($found), 'Naviera obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateShippingLineRequest $request, int $shippingLine, ShippingLineServiceInterface $shippingLineService)
    {
        try {
            $updated = $shippingLineService->update($shippingLine, $request->validated());

            return ResponseHandler::success(new ShippingLineResource($updated), 'Naviera actualizada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $shippingLine, ShippingLineServiceInterface $shippingLineService)
    {
        try {
            $deleted = $shippingLineService->destroy($shippingLine);

            return ResponseHandler::success(new ShippingLineResource($deleted), 'Naviera eliminada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the listing filters the service understands.
     *
     * @return array{search: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
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
