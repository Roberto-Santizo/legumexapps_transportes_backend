<?php

namespace App\Interfaces\Report;

use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Models\User;

interface ReportServiceInterface
{
    /**
     * Export the trips within the user's scope to a spreadsheet and publish it.
     *
     * The scope and the filters are exactly those of `TripServiceInterface::getTrips()`:
     * the export reads the full collection —`limit` is dropped if present— and keeps
     * at most `rows` of them. `total` counts every trip matching the filters, and
     * `truncated` tells whether the cap left some out. The file is stored under the
     * reports directory and `url` is the public address the browser can download it
     * from; `fileName` is a human name to show, not the key the file lives under.
     *
     * @param  array{status?: string|null, clientId?: string|null, shippingLineId?: string|null, locationId?: string|null, pilotId?: string|null, vehicleId?: string|null, dateFrom?: string|null, dateTo?: string|null, search?: string|null}  $filters
     * @return array{fileName: string, url: string, rows: int, total: int, truncated: bool}
     *
     * @throws BadRequestError when the spreadsheet cannot be written or stored
     */
    public function exportTrips(User $user, array $filters): array;

    /**
     * Export the maintenance expenses of one vehicle to a spreadsheet and publish it.
     *
     * Same contract as `exportTrips()` over `VehicleExpenseServiceInterface::getVehicleExpenses()`:
     * the scope of the vehicle applies untouched, `totalAmount` is the sum of every
     * expense matching the filters —not only the exported rows— and the invoice, when
     * there is one, travels as its public URL in its own column.
     *
     * @param  array{vehicleId: int, category?: string|null, nature?: string|null, dateFrom?: string|null, dateTo?: string|null, isInvoiced?: string|null}  $filters
     * @return array{fileName: string, url: string, rows: int, total: int, truncated: bool, totalAmount: string}
     *
     * @throws NotFoundError when the vehicle does not exist
     * @throws ForbiddenError when a carrier reaches a vehicle of another company
     * @throws BadRequestError when the spreadsheet cannot be written or stored
     */
    public function exportVehicleExpenses(User $user, array $filters): array;

    /**
     * Build the downloadable trips report and hand back its bytes.
     *
     * The scope and the filters are exactly those of `TripServiceInterface::getTrips()`
     * —`limit` is dropped—, so the report lists what the listing would. The columns
     * depend on the role: every authorized role gets the base columns and only some
     * roles get the finished products. Nothing is stored: `contents` are the bytes of
     * the spreadsheet and `fileName` is `viajes-{dateFrom}_{dateTo}.xlsx`.
     *
     * @param  array{dateFrom: string, dateTo: string, status?: string|null, clientId?: string|null, shippingLineId?: string|null, locationId?: string|null, pilotId?: string|null, vehicleId?: string|null, search?: string|null}  $filters
     * @return array{fileName: string, contents: string}
     *
     * @throws BadRequestError when the result exceeds MAX_ROWS or the spreadsheet cannot be written
     */
    public function downloadTrips(User $user, array $filters): array;
}
