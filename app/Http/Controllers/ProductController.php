<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\Product\ProductResource;
use App\Interfaces\Product\ProductServiceInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request, ProductServiceInterface $productService)
    {
        try {
            $products = $productService->getProducts($this->filters($request));

            $data = $products instanceof LengthAwarePaginator
                ? new PaginatedResource($products, ProductResource::class)
                : ProductResource::collection($products);

            return ResponseHandler::success($data, 'Productos obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreProductRequest $request, ProductServiceInterface $productService)
    {
        try {
            $product = $productService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new ProductResource($product), 'Producto registrado correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $product, ProductServiceInterface $productService)
    {
        try {
            $found = $productService->getProductById($product);

            return ResponseHandler::success(new ProductResource($found), 'Producto obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateProductRequest $request, int $product, ProductServiceInterface $productService)
    {
        try {
            $updated = $productService->update($product, $request->validated());

            return ResponseHandler::success(new ProductResource($updated), 'Producto actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function toggleStatus(int $product, ProductServiceInterface $productService)
    {
        try {
            $toggled = $productService->toggleStatus($product);

            return ResponseHandler::success(new ProductResource($toggled), 'Estado del producto actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $product, ProductServiceInterface $productService)
    {
        try {
            $deleted = $productService->destroy($product);

            return ResponseHandler::success(new ProductResource($deleted), 'Producto dado de baja correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Read the listing filters from the query string.
     *
     * Which of them are valid, and what to do with the invalid ones, is decided
     * by the service; here they are only normalized to strings, since anything
     * else is not a valid value.
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
     * Read a query string parameter, discarding anything that is not a string.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
