<?php

namespace App\Services\AccessoryCharacteristic;

use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\AccessoryCharacteristic\AccessoryCharacteristicServiceInterface;
use App\Models\Accessory;
use App\Models\AccessoryCharacteristic;
use App\Models\User;
use Override;

class AccessoryCharacteristicService implements AccessoryCharacteristicServiceInterface
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
     * Fields the update accepts.
     *
     * `accessory_id` and `registered_by` are deliberately absent: a characteristic
     * never moves between accessories, and it keeps the user that captured it.
     */
    private const UPDATABLE_FIELDS = ['name', 'value'];

    #[Override]
    public function getAccessoryCharacteristics(array $filters): array
    {
        /** Primero el accesorio: si no existe es 404, no un listado vacío. */
        $accessory = $this->resolveAccessory((int) $filters['accessoryId']);

        $query = AccessoryCharacteristic::query()
            ->with('registeredBy')
            ->where('accessory_id', '=', $accessory->id);

        /** Orden fijo: el orden en que se capturaron. No hay sortBy ni sortDir. */
        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return [
            'characteristics' => $perPage === null ? $query->get() : $query->paginate($perPage),
        ];
    }

    #[Override]
    public function createAccessoryCharacteristic(array $data, User $user): AccessoryCharacteristic
    {
        $accessory = $this->resolveAccessory((int) $data['accessory_id']);

        /** Se normaliza aquí aunque el FormRequest ya lo haya hecho: el service es llamable directamente. */
        $name = AccessoryCharacteristic::normalizeName($data['name']);

        $this->ensureNameIsAvailable($accessory->id, $name);

        $characteristic = AccessoryCharacteristic::create([
            'accessory_id' => $accessory->id,
            'name' => $name,
            /** Asimétrico a propósito: el valor solo se recorta, nunca se sube a mayúsculas. */
            'value' => AccessoryCharacteristic::normalizeValue($data['value']),
            /** No sale del body: quien captura es el usuario autenticado. */
            'registered_by' => $user->id,
        ]);

        return $characteristic->load('registeredBy');
    }

    #[Override]
    public function getAccessoryCharacteristicById(int $id): AccessoryCharacteristic
    {
        $characteristic = AccessoryCharacteristic::query()->with('registeredBy')->find($id);

        if ($characteristic === null) {
            throw new NotFoundError('La característica no existe');
        }

        return $characteristic;
    }

    #[Override]
    public function updateAccessoryCharacteristic(array $data, int $id): AccessoryCharacteristic
    {
        $characteristic = $this->getAccessoryCharacteristicById($id);

        /** Un accessory_id que llegue se cae aquí en silencio: la fila no cambia de accesorio. */
        $payload = array_intersect_key($data, array_flip(self::UPDATABLE_FIELDS));

        if (isset($payload['name'])) {
            $name = AccessoryCharacteristic::normalizeName($payload['name']);

            /**
             * Contra el accesorio ya guardado, no contra uno que venga en el cuerpo, e
             * ignorando la propia fila: reenviar su mismo nombre no choca consigo misma.
             */
            $this->ensureNameIsAvailable($characteristic->accessory_id, $name, $characteristic->id);

            $payload['name'] = $name;
        }

        if (isset($payload['value'])) {
            $payload['value'] = AccessoryCharacteristic::normalizeValue($payload['value']);
        }

        /** Un cuerpo vacío es un no-op que igualmente responde 200. */
        if ($payload !== []) {
            $characteristic->update($payload);
        }

        /** registered_by no se reescribe: sigue apuntando a quien capturó la característica. */
        return $characteristic->load('registeredBy');
    }

    #[Override]
    public function deleteAccessoryCharacteristic(int $id): AccessoryCharacteristic
    {
        $characteristic = $this->getAccessoryCharacteristicById($id);

        /** Borrado físico: no hay status ni SoftDeletes, así que un segundo DELETE es 404. */
        $characteristic->delete();

        return $characteristic;
    }

    /**
     * Refuse a name the same accessory already holds.
     *
     * Duplicates what the unique index of the table already covers, on purpose: without
     * it a collision would surface as a 500 from Postgres instead of a business error
     * in Spanish. The scope is the accessory, not the whole table: two different
     * accessories both having «PLACA» is normal, one having two is a capture mistake.
     * Throws a BadRequestError when the name is taken.
     *
     * @param  int|null  $ignoreId  row allowed to hold the name, so an update can resend its own.
     */
    private function ensureNameIsAvailable(int $accessoryId, string $name, ?int $ignoreId = null): void
    {
        $exists = AccessoryCharacteristic::query()
            ->where('accessory_id', '=', $accessoryId)
            ->where('name', '=', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new BadRequestError('El accesorio ya tiene una característica con ese nombre');
        }
    }

    /**
     * Resolve the accessory a characteristic hangs from.
     *
     * Its status is never looked at: an inactive or under_repair accessory lists,
     * accepts and edits characteristics like any other, because the record of a
     * retired part stays valid information.
     * Throws a NotFoundError when the accessory does not exist.
     */
    private function resolveAccessory(int $id): Accessory
    {
        $accessory = Accessory::query()->find($id);

        if ($accessory === null) {
            throw new NotFoundError('El accesorio no existe');
        }

        return $accessory;
    }

    /**
     * Resolve the page size requested by the client.
     *
     * A missing or non numeric limit means "do not paginate"; a numeric one is clamped
     * to [10, 100].
     */
    private function resolvePerPage(?string $limit): ?int
    {
        if ($limit === null || ! is_numeric($limit)) {
            return null;
        }

        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, (int) $limit));
    }
}
