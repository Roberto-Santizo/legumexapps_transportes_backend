<?php

namespace App\Interfaces\Vehicle;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

interface VehicleServiceInterface
{
    /**
     * List the vehicles the given user is allowed to see.
     *
     * A carrier only reaches the vehicles of its own company; an administrator
     * reaches every company's. Invalid filters are ignored instead of failing.
     *
     * @param  array{status?: string|null, carrierId?: string|null, condition?: string|null, engineNumber?: string|null, limit?: string|null}  $filters
     *                                                                                                                                                   status: value of VehicleStatus; carrierId: only honoured for an administrator;
     *                                                                                                                                                   condition: value of VehicleCondition, exact match; engineNumber: partial match
     *                                                                                                                                                   against the uppercased engine number; both are ignored when invalid, so the
     *                                                                                                                                                   full list is returned instead of an empty one;
     *                                                                                                                                                   limit: page size requested by the client, clamped to [10, 100].
     * @return LengthAwarePaginator<int, Vehicle>|Collection<int, Vehicle>
     */
    public function getVehicles(User $user, array $filters): LengthAwarePaginator|Collection;

    /**
     * Return the vehicle matching the given id, within the user's scope.
     */
    public function getVehicleById(User $user, int $id): Vehicle;

    /**
     * Register a vehicle for the company of the given user.
     *
     * @param  array{plate: string, brand: string, model: string, year: int, capacity: float, type: string, condition: string, kilometers_per_gallon: float, purchase_price: float, monthly_insurance_cost: float, mileage: int, engine_number: string, image: UploadedFile}  $data
     *                                                                                                                                                                                                                                                                               capacity travels in pounds; kilometers_per_gallon in km per gallon;
     *                                                                                                                                                                                                                                                                               purchase_price in GTQ; monthly_insurance_cost in GTQ per month;
     *                                                                                                                                                                                                                                                                               mileage in whole kilometers; engine_number is stored uppercased.
     */
    public function createVehicle(array $data, User $user): Vehicle;

    /**
     * Update the vehicle matching the given id, within the user's scope.
     *
     * Mileage carries its own authorization rule, checked here and not in any
     * middleware: sending a mileage that differs from the stored one throws a
     * ForbiddenError unless the user is an administrator, and no other change in
     * the payload is applied. Sending the value the vehicle already has is not a
     * change and goes through for any role.
     *
     * @param  array{plate?: string, brand?: string, model?: string, year?: int, capacity?: float, type?: string, condition?: string, kilometers_per_gallon?: float, purchase_price?: float, monthly_insurance_cost?: float, mileage?: int, engine_number?: string, image?: UploadedFile, status?: string}  $data
     */
    public function updateVehicle(array $data, int $id, User $user): Vehicle;

    /**
     * Deactivate the vehicle matching the given id, within the user's scope.
     *
     * The row is kept: only its status moves to inactive.
     */
    public function deleteVehicle(int $id, User $user): Vehicle;
}
