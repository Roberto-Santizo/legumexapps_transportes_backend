<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\FinishedProduct\StoreFinishedProductRequest;
use App\Http\Requests\FinishedProduct\UpdateFinishedProductRequest;
use App\Http\Resources\FinishedProduct\FinishedProductResource;
use App\Http\Resources\PaginatedResource;
use App\Interfaces\FinishedProduct\FinishedProductServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class FinishedProductController extends Controller
{
    public function index(Request $request, FinishedProductServiceInterface $finishedProductService)
    {
        try {
            $finishedProducts = $finishedProductService->getFinishedProducts($this->filters($request));

            $data = $finishedProducts instanceof LengthAwarePaginator
                ? new PaginatedResource($finishedProducts, FinishedProductResource::class)
                : FinishedProductResource::collection($finishedProducts);

            return ResponseHandler::success($data, 'Productos terminados obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreFinishedProductRequest $request, FinishedProductServiceInterface $finishedProductService)
    {
        try {
            $created = $finishedProductService->createFinishedProduct($request->validated(), auth('api')->user());

            return ResponseHandler::success(new FinishedProductResource($created), 'Producto terminado registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $finishedProduct, FinishedProductServiceInterface $finishedProductService)
    {
        try {
            $found = $finishedProductService->getFinishedProductById($finishedProduct);

            return ResponseHandler::success(new FinishedProductResource($found), 'Producto terminado obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateFinishedProductRequest $request, int $finishedProduct, FinishedProductServiceInterface $finishedProductService)
    {
        try {
            $updated = $finishedProductService->updateFinishedProduct($finishedProduct, $request->validated());

            return ResponseHandler::success(new FinishedProductResource($updated), 'Producto terminado actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $finishedProduct, FinishedProductServiceInterface $finishedProductService)
    {
        try {
            $deleted = $finishedProductService->deleteFinishedProduct($finishedProduct);

            return ResponseHandler::success(new FinishedProductResource($deleted), 'Producto terminado eliminado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Collect the listing filters the service understands.
     *
     * @return array{search: string|null, clientId: string|null, limit: string|null}
     */
    private function filters(Request $request): array
    {
        return [
            'search' => $this->queryString($request, 'search'),
            'clientId' => $this->queryString($request, 'clientId'),
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
