<?php

namespace App\Services\Vehicle;

use App\Enums\UserRole;
use App\Enums\VehicleCondition;
use App\Enums\VehicleStatus;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Storage\FileStorageServiceInterface;
use App\Interfaces\Storage\ImageProcessorServiceInterface;
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

    /**
     * Directory every vehicle image is stored under.
     */
    private const IMAGE_DIRECTORY = 'vehicles';

    public function __construct(
        private readonly ImageProcessorServiceInterface $imageProcessor,
        private readonly FileStorageServiceInterface $fileStorage,
    ) {}

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

        $condition = isset($filters['condition']) ? VehicleCondition::tryFrom($filters['condition']) : null;

        if ($condition !== null) {
            $query->where('condition', '=', $condition->value);
        }

        $engineNumber = $this->normalizeEngineNumber($filters['engineNumber'] ?? null);

        if ($engineNumber !== null) {
            $query->where('engine_number', 'like', "%{$engineNumber}%");
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

        /** El ámbito y la unicidad de la placa se validan antes de subir: si no, un alta condenada dejaría un archivo en el bucket. */
        $this->ensurePlateIsAvailable($plate);

        return Vehicle::create([
            'carrier_id' => $carrier->id,
            'plate' => $plate,
            'brand' => $data['brand'],
            'model' => $data['model'],
            'year' => $data['year'],
            'capacity' => $data['capacity'],
            'type' => $data['type'],
            'condition' => $data['condition'],
            'kilometers_per_gallon' => $data['kilometers_per_gallon'],
            'purchase_price' => $data['purchase_price'],
            'monthly_insurance_cost' => $data['monthly_insurance_cost'],
            'mileage' => $data['mileage'],
            'engine_number' => $this->normalizeEngineNumber($data['engine_number']),
            'image' => $this->storeImage($data['image']),
            'status' => VehicleStatus::Active,
        ]);
    }

    #[Override]
    public function updateVehicle(array $data, int $id, User $user): Vehicle
    {
        $vehicle = $this->getVehicleById($user, $id);

        /**
         * El kilometraje es el único campo con autorización propia, y se comprueba
         * antes de subir la imagen: un PATCH condenado no puede dejar un archivo
         * huérfano en el bucket. Reenviar el valor que el vehículo ya tiene no es
         * un cambio y no dispara nada.
         */
        if (array_key_exists('mileage', $data) && (int) $data['mileage'] !== $vehicle->mileage) {
            if ($user->role !== UserRole::Administrator) {
                throw new ForbiddenError('Solo un administrador puede modificar el kilometraje del vehículo');
            }

            $vehicle->mileage = (int) $data['mileage'];
        }

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

        foreach (['brand', 'model', 'year', 'capacity', 'type', 'condition',
            'kilometers_per_gallon', 'purchase_price', 'monthly_insurance_cost'] as $field) {
            if (array_key_exists($field, $data)) {
                $vehicle->{$field} = $data[$field];
            }
        }

        if (array_key_exists('engine_number', $data)) {
            $vehicle->engine_number = $this->normalizeEngineNumber($data['engine_number']);
        }

        $previousImage = null;

        if (array_key_exists('image', $data)) {
            $previousImage = $vehicle->image;

            $vehicle->image = $this->storeImage($data['image']);
        }

        $vehicle->save();

        /** El anterior se borra después de persistir: al revés, un fallo de escritura dejaría la fila apuntando a un objeto ya borrado. */
        if ($previousImage !== null) {
            $this->fileStorage->delete($previousImage);
        }

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
     * Normalize an engine number the same way the column stores it.
     *
     * Trims and uppercases the value, returning null when it is not a string or
     * when nothing is left. Storing it uppercased is what makes the LIKE filter
     * case insensitive without reaching for ILIKE.
     */
    private function normalizeEngineNumber(mixed $engineNumber): ?string
    {
        if (! is_string($engineNumber)) {
            return null;
        }

        $normalized = Str::upper(trim($engineNumber));

        return $normalized === '' ? null : $normalized;
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
     * Normalize an uploaded image and store it, returning its key.
     *
     * Processing runs before uploading, so a file that cannot be decoded is
     * rejected without having written anything to the bucket.
     */
    private function storeImage(UploadedFile $file): string
    {
        $image = $this->imageProcessor->normalizeSquare($file);

        return $this->fileStorage->store($image['contents'], self::IMAGE_DIRECTORY, $image['extension']);
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
