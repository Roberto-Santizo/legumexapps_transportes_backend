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
     * @param  array{status?: string|null, carrierId?: string|null, limit?: string|null}  $filters
     *                                                                                              status: value of VehicleStatus; carrierId: only honoured for an administrator;
     *                                                                                              limit: page size requested by the client, clamped to [10, 100].
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
     * @param  array{plate: string, brand: string, model: string, year: int, capacity: float, type: string, image: UploadedFile}  $data
     *                                                                                                                                   capacity travels in pounds.
     */
    public function createVehicle(array $data, User $user): Vehicle;

    /**
     * Update the vehicle matching the given id, within the user's scope.
     *
     * @param  array{plate?: string, brand?: string, model?: string, year?: int, capacity?: float, type?: string, image?: UploadedFile, status?: string}  $data
     */
    public function updateVehicle(array $data, int $id, User $user): Vehicle;

    /**
     * Deactivate the vehicle matching the given id, within the user's scope.
     *
     * The row is kept: only its status moves to inactive.
     */
    public function deleteVehicle(int $id, User $user): Vehicle;
}
