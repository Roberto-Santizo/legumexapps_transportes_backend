<?php

namespace App\Services\Vehicle;

use App\Enums\UserRole;
use App\Enums\VehicleStatus;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Vehicle\VehicleServiceInterface;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Override;

class VehicleService implements VehicleServiceInterface
{
    /**
     * Smallest page size accepted, so nobody sweeps the table row by row.
     */
    private const MIN_PER_PAGE = 10;

    /**
     * Largest page size accepted, so nobody asks for the whole table at once.
     */
    private const MAX_PER_PAGE = 100;

    #[Override]
    public function getVehicles(User $user, array $filters): LengthAwarePaginator|Collection
    {
        $query = Vehicle::query()->with('carrier');

        $scopedCarrierId = $this->resolveScopedCarrierId($user);

        if ($scopedCarrierId !== null) {
            $query->where('carrier_id', '=', $scopedCarrierId);
        } elseif (isset($filters['carrierId']) && is_numeric($filters['carrierId'])) {
            $query->where('carrier_id', '=', (int) $filters['carrierId']);
        }

        $status = isset($filters['status']) ? VehicleStatus::tryFrom($filters['status']) : null;

        if ($status !== null) {
            $query->where('status', '=', $status->value);
        }

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getVehicleById(User $user, int $id): Vehicle
    {
        $vehicle = Vehicle::query()->with('carrier')->find($id);

        if ($vehicle === null) {
            throw new NotFoundError('El vehículo no existe');
        }

        $scopedCarrierId = $this->resolveScopedCarrierId($user);

        if ($scopedCarrierId !== null && $vehicle->carrier_id !== $scopedCarrierId) {
            throw new ForbiddenError('No puedes acceder a un vehículo que no pertenece a tu empresa transportista');
        }

        return $vehicle;
    }

    #[Override]
    public function createVehicle(array $data, User $user): Vehicle
    {
        $carrier = $user->currentCarrier();

        if ($carrier === null) {
            throw new ForbiddenError('Necesitas pertenecer a una empresa transportista para registrar un vehículo');
        }

        $plate = Str::upper($data['plate']);

        $this->ensurePlateIsAvailable($plate);

        return Vehicle::create([
            'carrier_id' => $carrier->id,
            'plate' => $plate,
            'brand' => $data['brand'],
            'model' => $data['model'],
            'year' => $data['year'],
            'capacity' => $data['capacity'],
            'type' => $data['type'],
            'image' => $this->buildImageName($data['image']),
            'status' => VehicleStatus::Active,
        ]);
    }

    #[Override]
    public function updateVehicle(array $data, int $id, User $user): Vehicle
    {
        $vehicle = $this->getVehicleById($user, $id);

        $plate = array_key_exists('plate', $data) ? Str::upper($data['plate']) : $vehicle->plate;

        $status = array_key_exists('status', $data) ? VehicleStatus::from($data['status']) : $vehicle->status;

        /** A vehicle coming back into service has to hold a plate nobody else is using. */
        $isBackInService = $vehicle->status === VehicleStatus::Inactive && $status !== VehicleStatus::Inactive;

        if ($plate !== $vehicle->plate) {
            /** Resubmitting the plate the vehicle already holds is not a conflict with itself. */
            $this->ensurePlateIsAvailable($plate, $vehicle->id);
        } elseif ($isBackInService) {
            $this->ensurePlateIsAvailable(
                $plate,
                $vehicle->id,
                'No puedes reactivar este vehículo: su placa ya está registrada en otro vehículo que no está desactivado',
            );
        }

        $vehicle->plate = $plate;
        $vehicle->status = $status;

        foreach (['brand', 'model', 'year', 'capacity', 'type'] as $field) {
            if (array_key_exists($field, $data)) {
                $vehicle->{$field} = $data[$field];
            }
        }

        if (array_key_exists('image', $data)) {
            $vehicle->image = $this->buildImageName($data['image']);
        }

        $vehicle->save();

        return $vehicle;
    }

    #[Override]
    public function deleteVehicle(int $id, User $user): Vehicle
    {
        $vehicle = $this->getVehicleById($user, $id);

        /** El borrado real queda fuera de esta spec: dar de baja un vehículo es desactivarlo. */
        $vehicle->status = VehicleStatus::Inactive;

        $vehicle->save();

        return $vehicle;
    }

    /**
     * Fail when the given plate is already held by a vehicle still in service.
     *
     * The uniqueness of a plate is conditional — a deactivated vehicle releases
     * it — so it cannot live in a database index and is checked here instead.
     *
     * @param  string  $plate  Already normalized to upper case.
     * @param  int|null  $exceptId  Vehicle updating its own plate, never a conflict with itself.
     * @param  string|null  $message  Overrides the error when the plate is not what the caller was changing.
     */
    private function ensurePlateIsAvailable(string $plate, ?int $exceptId = null, ?string $message = null): void
    {
        $query = Vehicle::query()
            ->where('plate', '=', $plate)
            ->where('status', '!=', VehicleStatus::Inactive->value);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        if ($query->exists()) {
            throw new BadRequestError($message ?? 'La placa ya está registrada en un vehículo que no está desactivado');
        }
    }

    /**
     * Resolve the company the given user is restricted to.
     *
     * An administrator has no scope at all; anybody else only reaches the
     * vehicles of the company it belongs to.
     */
    private function resolveScopedCarrierId(User $user): ?int
    {
        if ($user->role === UserRole::Administrator) {
            return null;
        }

        $carrier = $user->currentCarrier();

        if ($carrier === null) {
            throw new ForbiddenError('No perteneces a ninguna empresa transportista');
        }

        return $carrier->id;
    }

    /**
     * Build the stored name of an uploaded image.
     *
     * The file itself is validated and discarded: uploading it is out of the
     * scope of this spec, only its identifier is persisted.
     */
    private function buildImageName(UploadedFile $file): string
    {
        return Str::uuid().'.'.$file->getClientOriginalExtension();
    }

    /**
     * Resolve the page size requested by the client.
     *
     * A missing or non numeric limit means "do not paginate"; a numeric one is
     * clamped to [10, 100].
     */
    private function resolvePerPage(?string $limit): ?int
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }
}
