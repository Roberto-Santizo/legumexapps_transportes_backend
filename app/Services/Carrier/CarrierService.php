<?php

namespace App\Services\Carrier;

use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Carrier\CarrierServiceInterface;
use App\Models\Carrier;
use App\Models\CarrierPilot;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Override;

class CarrierService implements CarrierServiceInterface
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
     * Alphabet of the company code: dictated over the phone without ambiguity.
     */
    private const CODE_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * Length of the company code.
     */
    private const CODE_LENGTH = 6;

    #[Override]
    public function getCarriers(?string $limit): LengthAwarePaginator|Collection
    {
        $perPage = $this->resolvePerPage($limit);

        $query = Carrier::query();

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getCarrierById(int $id): Carrier
    {
        $carrier = Carrier::query()->find($id);

        if ($carrier === null) {
            throw new NotFoundError('El transportista no existe');
        }

        return $carrier;
    }

    #[Override]
    public function getMyCarrier(User $user): Carrier
    {
        $carrier = $user->carrier;

        if ($carrier === null) {
            throw new NotFoundError('Todavía no has registrado tu empresa transportista');
        }

        return $carrier;
    }

    #[Override]
    public function getMyPilots(User $user, ?string $limit): LengthAwarePaginator|Collection
    {
        $carrier = $this->getMyCarrier($user);

        $perPage = $this->resolvePerPage($limit);

        $query = $carrier->pilots();

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function createCarrier(array $data, User $user): Carrier
    {
        if ($user->carrier !== null) {
            throw new BadRequestError('Ya tienes una empresa transportista registrada');
        }

        return Carrier::create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'image' => $this->buildImageName($data['image']),
            'code' => $this->generateUniqueCode(),
            'active' => true,
        ]);
    }

    #[Override]
    public function updateCarrier(array $data, int $id, User $user): Carrier
    {
        $carrier = $this->getCarrierById($id);

        if ($user->role === UserRole::Carrier && $carrier->user_id !== $user->id) {
            throw new ForbiddenError('No puedes actualizar una empresa transportista que no te pertenece');
        }

        if (array_key_exists('name', $data)) {
            $carrier->name = $data['name'];
        }

        if (array_key_exists('image', $data)) {
            $carrier->image = $this->buildImageName($data['image']);
        }

        if (array_key_exists('active', $data)) {
            $carrier->active = $data['active'];
        }

        $carrier->save();

        return $carrier;
    }

    #[Override]
    public function deleteCarrier(int $id): void
    {
        /** El borrado real queda fuera de esta spec: se resuelve la empresa para responder 404 si no existe, y nada más. */
        $this->getCarrierById($id);
    }

    #[Override]
    public function joinCarrier(array $data, User $user): void
    {
        $code = Str::upper($data['code']);

        $carrier = Carrier::query()->where('code', '=', $code)->first();

        if ($carrier === null) {
            throw new NotFoundError('El código no pertenece a ninguna empresa transportista');
        }

        if ($user->currentCarrier() !== null) {
            throw new BadRequestError('Ya perteneces a una empresa transportista');
        }

        CarrierPilot::create([
            'carrier_id' => $carrier->id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * Generate a company code that no other company holds yet.
     *
     * The unique index on the column is the final safety net.
     */
    private function generateUniqueCode(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (Carrier::query()->where('code', '=', $code)->exists());

        return $code;
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
