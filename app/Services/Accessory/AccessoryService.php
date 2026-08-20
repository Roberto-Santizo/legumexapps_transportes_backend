<?php

namespace App\Services\Accessory;

use App\Enums\AccessoryStatus;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\Accessory\AccessoryServiceInterface;
use App\Models\Accessory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Override;

class AccessoryService implements AccessoryServiceInterface
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
    public function getAccessories(array $filters): LengthAwarePaginator|Collection
    {
        $query = Accessory::query()->with('registeredBy');

        /** Cualquier valor fuera del enum se ignora en vez de vaciar el listado. */
        $status = AccessoryStatus::tryFrom((string) ($filters['status'] ?? ''));

        if ($status !== null) {
            $query->where('status', '=', $status->value);
        }

        /**
         * El término se normaliza como un nombre —colapsando espacios— porque es la
         * búsqueda habitual; name y code están siempre en mayúsculas, así que con eso
         * basta para ser insensible a mayúsculas contra los dos.
         */
        $search = Accessory::normalizeName($filters['search'] ?? '');

        if ($search !== '') {
            /** Agrupado: sin el paréntesis, el orWhere se saltaría el filtro de status. */
            $query->where(function ($query) use ($search) {
                $query->where('name', 'LIKE', '%'.$search.'%')
                    ->orWhere('code', 'LIKE', '%'.$search.'%');
            });
        }

        /** El inventario se lee en el orden en que se dio de alta. */
        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function createAccessory(array $data, User $user): Accessory
    {
        /** Se normaliza aquí aunque el FormRequest ya lo haya hecho: el service es llamable directamente. */
        $name = Accessory::normalizeName($data['name']);

        $this->ensureNameIsAvailable($name);

        $code = Accessory::normalizeCode($data['code']);

        $this->ensureCodeIsAvailable($code);

        $accessory = Accessory::create([
            'name' => $name,
            'code' => $code,
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'purchase_date' => $data['purchaseDate'],
            'annual_depreciation' => $data['annualDepreciation'],
            /** El estado no sale del body: un accesorio nace siempre activo. */
            'status' => AccessoryStatus::Active,
            'registered_by' => $user->id,
        ]);

        return $accessory->load('registeredBy');
    }

    #[Override]
    public function getAccessoryById(int $id): Accessory
    {
        $accessory = Accessory::query()->with('registeredBy')->find($id);

        if ($accessory === null) {
            throw new NotFoundError('El accesorio no existe');
        }

        return $accessory;
    }

    #[Override]
    public function updateAccessory(array $data, int $id): Accessory
    {
        $accessory = $this->getAccessoryById($id);

        if (isset($data['name'])) {
            $name = Accessory::normalizeName($data['name']);

            /** Se ignora la propia fila: reenviar su mismo nombre no puede chocar consigo misma. */
            $this->ensureNameIsAvailable($name, $accessory->id);

            $accessory->name = $name;
        }

        if (isset($data['code'])) {
            $code = Accessory::normalizeCode($data['code']);

            $this->ensureCodeIsAvailable($code, $accessory->id);

            $accessory->code = $code;
        }

        /** La descripción se borra mandando null, así que no basta con isset(). */
        if (array_key_exists('description', $data)) {
            $accessory->description = $data['description'];
        }

        if (isset($data['price'])) {
            $accessory->price = $data['price'];
        }

        if (isset($data['purchaseDate'])) {
            $accessory->purchase_date = $data['purchaseDate'];
        }

        if (isset($data['annualDepreciation'])) {
            $accessory->annual_depreciation = $data['annualDepreciation'];
        }

        /** El estado se mueve libremente entre los tres valores: no hay reglas de transición. */
        if (isset($data['status'])) {
            $accessory->status = $data['status'];
        }

        /** registered_by no se reescribe: sigue apuntando a quien dio de alta el accesorio. */
        $accessory->save();

        return $accessory->load('registeredBy');
    }

    #[Override]
    public function deleteAccessory(int $id): Accessory
    {
        $accessory = $this->getAccessoryById($id);

        /** Baja lógica e idempotente: sobre uno ya inactivo no falla y lo deja igual. */
        $accessory->status = AccessoryStatus::Inactive;

        $accessory->save();

        return $accessory;
    }

    /**
     * Refuse a name that another accessory already holds.
     *
     * Duplicates what the unique rule of the request and the unique index of the table
     * already cover, on purpose: without it a direct call to the service would surface
     * the index violation as a 500 instead of a business error.
     * Throws a BadRequestError when the name is taken.
     *
     * @param  int|null  $ignoreId  row allowed to hold the name, so an update can resend its own.
     */
    private function ensureNameIsAvailable(string $name, ?int $ignoreId = null): void
    {
        $exists = Accessory::query()
            ->where('name', '=', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new BadRequestError('Ya existe un accesorio con ese nombre');
        }
    }

    /**
     * Refuse a code that another accessory already holds.
     *
     * The status of the row holding it is never looked at: a code stays taken forever,
     * because an accessory taken down keeps the serial written on its label. This is
     * the deliberate difference with a vehicle plate, which an inactive vehicle frees.
     * Throws a BadRequestError when the code is taken.
     *
     * @param  int|null  $ignoreId  row allowed to hold the code, so an update can resend its own.
     */
    private function ensureCodeIsAvailable(string $code, ?int $ignoreId = null): void
    {
        $exists = Accessory::query()
            ->where('code', '=', $code)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new BadRequestError('Ya existe un accesorio con ese código');
        }
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
