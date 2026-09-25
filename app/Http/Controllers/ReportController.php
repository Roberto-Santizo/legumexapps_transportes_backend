<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Report\ExportTripsReportRequest;
use App\Interfaces\Report\ReportServiceInterface;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * MIME type of every spreadsheet this controller sends.
     */
    private const string XLSX_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * Download the trips of a date range as an `.xlsx` attachment.
     *
     * The only response of the API that is neither the JSON envelope nor a stream: on
     * success the body is the spreadsheet itself. Errors still go through `ResponseHandler`.
     */
    public function trips(ExportTripsReportRequest $request, ReportServiceInterface $reportService)
    {
        try {
            $report = $reportService->downloadTrips(auth('api')->user(), [
                ...$this->filters($request),
                ...$request->validated(),
            ]);

            return response($report['contents'], 200, [
                'Content-Type' => self::XLSX_CONTENT_TYPE,
                'Content-Disposition' => 'attachment; filename="'.$report['fileName'].'"',
            ]);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }

    /**
     * The optional filters of `GET /api/trips`, read as strings; `limit` is not read.
     *
     * An array or a nested value comes back as null, so `?status[]=pending` is ignored
     * instead of blowing up inside the service, as in `TripController`.
     *
     * @return array<string, string|null>
     */
    private function filters(Request $request): array
    {
        $filters = [];

        foreach (['status', 'clientId', 'shippingLineId', 'locationId', 'pilotId', 'vehicleId', 'search'] as $key) {
            $value = $request->query($key);
            $filters[$key] = is_string($value) ? $value : null;
        }

        return $filters;
    }
}
