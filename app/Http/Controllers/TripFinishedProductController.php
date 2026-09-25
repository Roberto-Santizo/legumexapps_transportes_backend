<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\TripFinishedProduct\IndexTripFinishedProductRequest;
use App\Http\Requests\TripFinishedProduct\StoreTripFinishedProductRequest;
use App\Http\Requests\TripFinishedProduct\UpdateTripFinishedProductRequest;
use App\Http\Resources\TripFinishedProduct\TripFinishedProductResource;
use App\Interfaces\TripFinishedProduct\TripFinishedProductServiceInterface;

class TripFinishedProductController extends Controller
{
    public function index(IndexTripFinishedProductRequest $request, TripFinishedProductServiceInterface $tripFinishedProductService)
    {
        try {
            $lines = $tripFinishedProductService->getTripFinishedProducts(auth('api')->user(), (int) $request->validated('tripId'));

            return ResponseHandler::success(TripFinishedProductResource::collection($lines), 'Productos del viaje obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function store(StoreTripFinishedProductRequest $request, TripFinishedProductServiceInterface $tripFinishedProductService)
    {
        try {
            $created = $tripFinishedProductService->createTripFinishedProduct($request->validated(), auth('api')->user());

            return ResponseHandler::success(new TripFinishedProductResource($created), 'Producto agregado al viaje correctamente', 201);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function update(UpdateTripFinishedProductRequest $request, int $tripFinishedProduct, TripFinishedProductServiceInterface $tripFinishedProductService)
    {
        try {
            $updated = $tripFinishedProductService->updateTripFinishedProduct($tripFinishedProduct, $request->validated());

            return ResponseHandler::success(new TripFinishedProductResource($updated), 'Producto del viaje actualizado correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function destroy(int $tripFinishedProduct, TripFinishedProductServiceInterface $tripFinishedProductService)
    {
        try {
            $deleted = $tripFinishedProductService->deleteTripFinishedProduct($tripFinishedProduct);

            return ResponseHandler::success(new TripFinishedProductResource($deleted), 'Producto eliminado del viaje correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }
}
