<?php

namespace App\Services\DeparturePoint;

use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\DeparturePoint\DeparturePointServiceInterface;
use App\Models\DeparturePoint;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Override;

class DeparturePointService implements DeparturePointServiceInterface
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
    public function getDeparturePoints(array $filters): LengthAwarePaginator|Collection
    {
        $query = DeparturePoint::query()->with('registeredBy');

        /** Cualquier valor que no sea booleano se ignora en vez de vaciar el listado. */
        $status = isset($filters['status'])
            ? filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        if ($status !== null) {
            $query->where('status', '=', $status);
        }

        $search = DeparturePoint::normalizeName($filters['search'] ?? '');

        if ($search !== '') {
            /** El name está siempre en mayúsculas, así que normalizar el término basta para ser insensible a mayúsculas. */
            $query->where('name', 'LIKE', '%'.$search.'%');
        }

        /** Los puntos de partida se leen en el orden en que se dieron de alta. */
        $query->orderBy('id');

        $perPage = $this->resolvePerPage($filters['limit'] ?? null);

        return $perPage === null ? $query->get() : $query->paginate($perPage);
    }

    #[Override]
    public function getDeparturePointById(int $id): DeparturePoint
    {
        $departurePoint = DeparturePoint::query()->with('registeredBy')->find($id);

        if ($departurePoint === null) {
            throw new NotFoundError('El punto de partida no existe');
        }

        return $departurePoint;
    }

    #[Override]
    public function create(User $user, array $data): DeparturePoint
    {
        /** Se normaliza aquí aunque el FormRequest ya lo haya hecho: el service es llamable directamente. */
        $name = DeparturePoint::normalizeName($data['name']);

        $this->ensureNameIsAvailable($name);

        /** El id de Google no se normaliza: es opaco y sensible a mayúsculas. */
        $googlePlaceId = $data['googlePlaceId'];

        $this->ensureGooglePlaceIdIsAvailable($googlePlaceId);

        $departurePoint = DeparturePoint::create([
            'name' => $name,
            'description' => $data['description'] ?? null,
            'google_place_id' => $googlePlaceId,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            /** El estado no sale del body: un punto de partida nace siempre activo. */
            'status' => true,
            'registered_by' => $user->id,
        ]);

        return $departurePoint->load('registeredBy');
    }

    #[Override]
    public function update(int $id, array $data): DeparturePoint
    {
        $departurePoint = $this->getDeparturePointById($id);

        if (isset($data['name'])) {
            $name = DeparturePoint::normalizeName($data['name']);

            /** Se ignora la propia fila: reenviar su mismo nombre no puede chocar consigo misma. */
            $this->ensureNameIsAvailable($name, $departurePoint->id);

            $departurePoint->name = $name;
        }

        /** La descripción se borra mandando null, así que no basta con isset(). */
        if (array_key_exists('description', $data)) {
            $departurePoint->description = $data['description'];
        }

        if (isset($data['googlePlaceId'])) {
            /**
             * Reapuntar el punto a otro lugar conserva la fila y su id. No hay validación
             * cruzada con las coordenadas: cambiar solo el lugar es válido y deja el pin
             * anterior, que es el riesgo asumido a cambio de no perder el historial.
             */
            $this->ensureGooglePlaceIdIsAvailable($data['googlePlaceId'], $departurePoint->id);

            $departurePoint->google_place_id = $data['googlePlaceId'];
        }

        if (isset($data['latitude'])) {
            $departurePoint->latitude = $data['latitude'];
        }

        if (isset($data['longitude'])) {
            $departurePoint->longitude = $data['longitude'];
        }

        if (isset($data['status'])) {
            $departurePoint->status = $data['status'];
        }

        /** registered_by no se reescribe: sigue apuntando a quien dio de alta el punto. */
        $departurePoint->save();

        return $departurePoint->load('registeredBy');
    }

    #[Override]
    public function toggleStatus(int $id): DeparturePoint
    {
        $departurePoint = $this->getDeparturePointById($id);

        $departurePoint->status = ! $departurePoint->status;

        $departurePoint->save();

        return $departurePoint;
    }

    #[Override]
    public function destroy(int $id): DeparturePoint
    {
        $departurePoint = $this->getDeparturePointById($id);

        /** Baja lógica e idempotente: sobre un punto ya inactivo no falla y lo deja igual. */
        $departurePoint->status = false;

        $departurePoint->save();

        return $departurePoint;
    }

    /**
     * Refuse a name that another departure point already holds.
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
        $exists = DeparturePoint::query()
            ->where('name', '=', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new BadRequestError('Ya existe un punto de partida con ese nombre');
        }
    }

    /**
     * Refuse a google place id that another departure point already points at.
     *
     * Two rows aiming at the same place are a duplicate by definition. The row holding
     * it is looked up rather than merely counted, so the error can name it and whoever
     * captured the duplicate knows where to look. Only this table is consulted: the
     * same place may also be registered as a location, and that is not a conflict.
     * Throws a BadRequestError when the place is taken.
     *
     * @param  int|null  $ignoreId  row allowed to hold the place, so an update can resend its own.
     */
    private function ensureGooglePlaceIdIsAvailable(string $googlePlaceId, ?int $ignoreId = null): void
    {
        $departurePoint = DeparturePoint::query()
            ->where('google_place_id', '=', $googlePlaceId)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->first();

        if ($departurePoint !== null) {
            throw new BadRequestError('El lugar seleccionado ya está registrado en el punto de partida '.$departurePoint->name);
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
