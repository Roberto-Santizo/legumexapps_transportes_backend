<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Place\SearchPlacesRequest;
use App\Http\Resources\Place\PlacePredictionResource;
use App\Http\Resources\Place\PlaceResource;
use App\Interfaces\Place\PlaceServiceInterface;

class PlaceController extends Controller
{
    public function index(SearchPlacesRequest $request, PlaceServiceInterface $placeService)
    {
        try {
            $places = $placeService->searchPlaces($request->validated('search'));

            /** Este listado no pagina nunca: el proveedor devuelve diez y se acabó. */
            $data = PlacePredictionResource::collection($places);

            return ResponseHandler::success($data, 'Direcciones obtenidas correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function show(string $place, PlaceServiceInterface $placeService)
    {
        try {
            $found = $placeService->getPlaceById($place);

            return ResponseHandler::success(new PlaceResource($found), 'Dirección obtenida correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }
}
