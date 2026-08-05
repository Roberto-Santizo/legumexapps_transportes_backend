<?php

namespace App\Interfaces\Carrier;

use App\Models\Carrier;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

interface CarrierServiceInterface
{
    /**
     * List every company, paginated only when a numeric limit is given.
     *
     * @param  string|null  $limit  Page size requested by the client; clamped to [10, 100].
     * @return LengthAwarePaginator<int, Carrier>|Collection<int, Carrier>
     */
    public function getCarriers(?string $limit): LengthAwarePaginator|Collection;

    /**
     * Return the company matching the given id.
     */
    public function getCarrierById(int $id): Carrier;

    /**
     * Return the company owned by the given user.
     */
    public function getMyCarrier(User $user): Carrier;

    /**
     * List the pilots of the company owned by the given user.
     *
     * @param  string|null  $limit  Page size requested by the client; clamped to [10, 100].
     * @return LengthAwarePaginator<int, User>|Collection<int, User>
     */
    public function getMyPilots(User $user, ?string $limit): LengthAwarePaginator|Collection;

    /**
     * Create the company of the given user, generating its unique code.
     *
     * @param  array{name: string, image: UploadedFile}  $data
     */
    public function createCarrier(array $data, User $user): Carrier;

    /**
     * Update the company matching the given id.
     *
     * @param  array{name?: string, image?: UploadedFile, active?: bool}  $data
     */
    public function updateCarrier(array $data, int $id, User $user): Carrier;

    /**
     * Delete the company matching the given id.
     */
    public function deleteCarrier(int $id): void;

    /**
     * Link the given pilot to the company owning the submitted code.
     *
     * @param  array{code: string}  $data
     */
    public function joinCarrier(array $data, User $user): void;
}
