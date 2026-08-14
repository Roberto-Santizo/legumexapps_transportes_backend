<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\FreightRate\QuoteFreightRateRequest;
use App\Http\Requests\FreightRate\StoreFreightRateRequest;
use App\Http\Requests\FreightRate\UpdateFreightRateRequest;
use App\Http\Resources\FreightRate\FreightQuoteResource;
use App\Http\Resources\FreightRate\FreightRateResource;
use App\Interfaces\FreightRate\FreightRateServiceInterface;
use Illuminate\Http\Request;

class FreightRateController extends Controller
{
    public function index(Request $request, FreightRateServiceInterface $freightRateService)
    {
        try {
            /** Nunca pagina: la tabla de precios se lee entera. */
            $rates = $freightRateService->getFreightRates([
                'zoneId' => $this->queryString($request, 'zoneId'),
            ]);

            return ResponseHandler::success(
                FreightRateResource::collection($rates),
                'Tarifas obtenidas correctamente',
                200,
            );
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function quote(QuoteFreightRateRequest $request, FreightRateServiceInterface $freightRateService)
    {
        try {
            $quote = $freightRateService->quote($request->validated());

            return ResponseHandler::success(new FreightQuoteResource($quote), 'Cotización obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreFreightRateRequest $request, FreightRateServiceInterface $freightRateService)
    {
        try {
            $rate = $freightRateService->create(auth('api')->user(), $request->validated());

            return ResponseHandler::success(new FreightRateResource($rate), 'Tarifa registrada correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(int $freightRate, FreightRateServiceInterface $freightRateService)
    {
        try {
            $rate = $freightRateService->getFreightRateById($freightRate);

            return ResponseHandler::success(new FreightRateResource($rate), 'Tarifa obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateFreightRateRequest $request, int $freightRate, FreightRateServiceInterface $freightRateService)
    {
        try {
            $rate = $freightRateService->update($freightRate, $request->validated());

            return ResponseHandler::success(new FreightRateResource($rate), 'Tarifa actualizada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $freightRate, FreightRateServiceInterface $freightRateService)
    {
        try {
            $deleted = $freightRateService->destroy($freightRate);

            return ResponseHandler::success(new FreightRateResource($deleted), 'Tarifa eliminada correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * Read a query parameter only when it arrived as a string.
     *
     * An array in the query string —zoneId[]=1— would otherwise reach the service as an
     * array and blow up a comparison that expects a scalar.
     */
    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
