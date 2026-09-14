<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Resources\Dashboard\TripsSummaryResource;
use App\Http\Resources\Dashboard\VehicleExpensesSummaryResource;
use App\Interfaces\Dashboard\DashboardServiceInterface;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function trips(Request $request, DashboardServiceInterface $dashboardService)
    {
        try {
            $summary = $dashboardService->getTripsSummary([
                'carrierId' => $this->queryString($request, 'carrierId'),
                'dateFrom' => $this->queryString($request, 'dateFrom'),
                'dateTo' => $this->queryString($request, 'dateTo'),
            ]);

            return ResponseHandler::success(new TripsSummaryResource($summary), 'Resumen de viajes obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function tripsInRoute(Request $request, DashboardServiceInterface $dashboardService)
    {
        try {
            $trips = $dashboardService->getTripsInRoute([
                'carrierId' => $this->queryString($request, 'carrierId'),
            ]);

            return ResponseHandler::success($trips, 'Viajes en curso obtenidos correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function vehicleExpenses(Request $request, DashboardServiceInterface $dashboardService)
    {
        try {
            $summary = $dashboardService->getVehicleExpensesSummary([
                'carrierId' => $this->queryString($request, 'carrierId'),
                'dateFrom' => $this->queryString($request, 'dateFrom'),
                'dateTo' => $this->queryString($request, 'dateTo'),
            ]);

            return ResponseHandler::success(new VehicleExpensesSummaryResource($summary), 'Resumen de gastos de vehículos obtenido correctamente', 200);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    public function vehicles(Request $request, DashboardServiceInterface $dashboardService)
    {
        try {
            $vehicles = $dashboardService->getVehicles([
                'carrierId' => $this->queryString($request, 'carrierId'),
                'status' => $this->queryString($request, 'status'),
                'condition' => $this->queryString($request, 'condition'),
                'inRoute' => $this->queryString($request, 'inRoute'),
                'limit' => $this->queryString($request, 'limit'),
            ]);

            return ResponseHandler::success($vehicles, 'Flota obtenida correctamente', 200);
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
