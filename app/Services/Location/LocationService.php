<?php

namespace App\Services\Location;

use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\Location\LocationServiceInterface;
use App\Models\Location;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Override;

class LocationService implements LocationServiceInterface
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
    public function getLocations(array $filters): LengthAwarePaginator|Collection
    {
        $query = Location::query()->with('registeredBy');

        /** Cualquier valor que no sea booleano se ignora en vez de vaciar el listado. */
        $status = isset($filters['status'])
            ? filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        if ($status !== null) {
            $query->where('status', '=', $status);
        }

        $search = Location::normalizeName($filters['search'] ?? '');

        if ($search !== '') {
            /** El name está siempre en mayúsculas, así que normalizar el término basta para ser insensible a mayúsculas. */
            $query->where('name', 'LIKE', '%'.$search.'%');
        }

        /** Los destinos se leen en el orden en que se dieron de alta. */
        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getLocationById(int $id): Location
    {
        $location = Location::query()->with('registeredBy')->find($id);

        if ($location === null) {
            throw new NotFoundError('El destino no existe');
        }

        return $location;
    }

    #[Override]
    public function create(User $user, array $data): Location
    {
        /** Se normaliza aquí aunque el FormRequest ya lo haya hecho: el service es llamable directamente. */
        $name = Location::normalizeName($data['name']);

        $this->ensureNameIsAvailable($name);

        /** El id de Google no se normaliza: es opaco y sensible a mayúsculas. */
        $googlePlaceId = $data['google_place_id'];

        $this->ensureGooglePlaceIdIsAvailable($googlePlaceId);

        $location = Location::create([
            'name' => $name,
            'description' => $data['description'] ?? null,
            'google_place_id' => $googlePlaceId,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            /** El estado no sale del body: un destino nace siempre activo. */
            'status' => true,
            'registered_by' => $user->id,
        ]);

        return $location->load('registeredBy');
    }

    #[Override]
    public function update(int $id, array $data): Location
    {
        $location = $this->getLocationById($id);

        if (isset($data['name'])) {
            $name = Location::normalizeName($data['name']);

            /** Se ignora la propia fila: reenviar su mismo nombre no puede chocar consigo misma. */
            $this->ensureNameIsAvailable($name, $location->id);

            $location->name = $name;
        }

        /** La descripción se borra mandando null, así que no basta con isset(). */
        if (array_key_exists('description', $data)) {
            $location->description = $data['description'];
        }

        if (isset($data['google_place_id'])) {
            /**
             * Reapuntar el destino a otro lugar conserva la fila, su id y sus tarifas. No hay
             * validación cruzada con las coordenadas: cambiar solo el lugar es válido y deja
             * el pin anterior, que es el riesgo asumido a cambio de no perder el historial.
             */
            $this->ensureGooglePlaceIdIsAvailable($data['google_place_id'], $location->id);

            $location->google_place_id = $data['google_place_id'];
        }

        if (isset($data['latitude'])) {
            $location->latitude = $data['latitude'];
        }

        if (isset($data['longitude'])) {
            $location->longitude = $data['longitude'];
        }

        if (isset($data['status'])) {
            $location->status = $data['status'];
        }

        /** registered_by no se reescribe: sigue apuntando a quien dio de alta el destino. */
        $location->save();

        return $location->load('registeredBy');
    }

    #[Override]
    public function toggleStatus(int $id): Location
    {
        $location = $this->getLocationById($id);

        /** No se mira si el destino tiene tarifas: desactivarlo es decisión del administrador. */
        $location->status = ! $location->status;

        $location->save();

        return $location;
    }

    #[Override]
    public function destroy(int $id): Location
    {
        $location = $this->getLocationById($id);

        /** Baja lógica e idempotente: sobre un destino ya inactivo no falla y lo deja igual. */
        $location->status = false;

        $location->save();

        return $location;
    }

    /**
     * Refuse a name that another location already holds.
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
        $exists = Location::query()
            ->where('name', '=', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new BadRequestError('Ya existe un destino con ese nombre');
        }
    }

    /**
     * Refuse a google place id that another location already points at.
     *
     * Two rows aiming at the same place are a duplicate by definition. The row holding
     * it is looked up rather than merely counted, so the error can name it and whoever
     * captured the duplicate knows where to look.
     * Throws a BadRequestError when the place is taken.
     *
     * @param  int|null  $ignoreId  row allowed to hold the place, so an update can resend its own.
     */
    private function ensureGooglePlaceIdIsAvailable(string $googlePlaceId, ?int $ignoreId = null): void
    {
        $location = Location::query()
            ->where('google_place_id', '=', $googlePlaceId)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->first();

        if ($location !== null) {
            throw new BadRequestError('El lugar seleccionado ya está registrado en el destino '.$location->name);
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
