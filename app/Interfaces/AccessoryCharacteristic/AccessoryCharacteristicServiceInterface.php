<?php

namespace App\Interfaces\AccessoryCharacteristic;

use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Models\AccessoryCharacteristic;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface AccessoryCharacteristicServiceInterface
{
    /**
     * List the characteristics of a single accessory, oldest first.
     *
     * The listing never spans the whole inventory: `accessoryId` is required by the
     * caller, and an accessory that does not exist is a 404 rather than an empty
     * list, so the client can tell "no such accessory" from "no characteristics
     * yet". The accessory's status is irrelevant: an inactive or under_repair
     * accessory lists its characteristics like any other.
     *
     * No user is taken: the inventory is national, so there is no company scoping
     * to resolve, and the role filter already ran in the middleware.
     *
     * Results are ordered by `id` ascending — the order they were captured in —
     * and there is no other filter, no search and no configurable sorting.
     *
     * @param  array{accessoryId: int, limit?: string|null}  $filters
     *                                                                 accessoryId: already validated as required by the FormRequest, but not as
     *                                                                 existing — that 404 is raised here;
     *                                                                 limit: page size requested by the client, clamped to [10, 100].
     * @return array{characteristics: LengthAwarePaginator<int, AccessoryCharacteristic>|Collection<int, AccessoryCharacteristic>}
     *                                                                                                                             wrapped in an array to keep the shape of VehicleExpenseServiceInterface, the
     *                                                                                                                             other listing of the project that requires a parent id.
     *
     * @throws NotFoundError when the accessory does not exist
     */
    public function getAccessoryCharacteristics(array $filters): array;

    /**
     * Add a characteristic to an accessory.
     *
     * `name` and `value` are normalized asymmetrically before being persisted: the
     * name is trimmed, collapsed and upper cased, the value is only trimmed.
     * `registered_by` is taken from the given user, never from the payload, and the
     * accessory's status is not checked: a piece taken down still accepts new
     * characteristics, because the record of a retired part stays valid information.
     *
     * @param  array{accessory_id: int, name: string, value: string}  $data
     *
     * @throws NotFoundError when the accessory does not exist
     * @throws BadRequestError when the accessory already has a characteristic with that name
     */
    public function createAccessoryCharacteristic(array $data, User $user): AccessoryCharacteristic;

    /**
     * Return the characteristic matching the given id.
     *
     * @throws NotFoundError when the characteristic does not exist
     */
    public function getAccessoryCharacteristicById(int $id): AccessoryCharacteristic;

    /**
     * Update the given fields on the characteristic matching the given id.
     *
     * The accessory is immutable: `accessory_id` is not accepted, so a characteristic
     * never moves between accessories — moving one is deleting it and creating it
     * again. `registered_by` is not rewritten either: it keeps pointing at whoever
     * captured the characteristic. A name that arrives is normalized and checked for
     * availability against the stored accessory, ignoring this same row, so resending
     * the row's own name is accepted. An empty payload is a no-op that still answers 200.
     *
     * @param  array{name?: string, value?: string}  $data
     *
     * @throws NotFoundError when the characteristic does not exist
     * @throws BadRequestError when the accessory already has another characteristic with that name
     */
    public function updateAccessoryCharacteristic(array $data, int $id): AccessoryCharacteristic;

    /**
     * Delete the characteristic matching the given id.
     *
     * The deletion is real: there is no status and no soft delete, so the row is
     * removed from the table and a second delete of the same id answers 404. It
     * touches neither the accessory nor its other characteristics. The returned
     * model is the already deleted one, kept so the caller can render what
     * disappeared.
     *
     * @throws NotFoundError when the characteristic does not exist
     */
    public function deleteAccessoryCharacteristic(int $id): AccessoryCharacteristic;
}
