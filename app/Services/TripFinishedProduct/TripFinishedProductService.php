<?php

namespace App\Services\TripFinishedProduct;

use App\Enums\TripStatus;
use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\Trip\TripServiceInterface;
use App\Interfaces\TripFinishedProduct\TripFinishedProductServiceInterface;
use App\Models\FinishedProduct;
use App\Models\Trip;
use App\Models\TripFinishedProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Override;

class TripFinishedProductService implements TripFinishedProductServiceInterface
{
    /**
     * Relations every response needs, eager loaded so the listing has no N+1.
     */
    private const RELATIONS = ['finishedProduct', 'registeredBy'];

    /**
     * The trip domain resolves the reading scope of SPEC 24.
     *
     * Injected by constructor —the by method parameter rule is the controller's alone—
     * so this service never rewrites that matrix. Precedent: TripFuelService (SPEC 27).
     * The reverse dependency does not exist: TripService writes the lines of a new trip
     * with the model directly, or the container would close a cycle.
     */
    public function __construct(private TripServiceInterface $tripService) {}

    #[Override]
    public function getTripFinishedProducts(User $user, int $tripId): Collection
    {
        /** getTripById() ya lanza 404 (inexistente o borrado) y 403 fuera del ámbito. */
        $trip = $this->tripService->getTripById($user, $tripId);

        return TripFinishedProduct::query()
            ->with(self::RELATIONS)
            ->where('trip_id', '=', $trip->id)
            ->orderBy('id')
            ->get();
    }

    #[Override]
    public function createTripFinishedProduct(array $data, User $user): TripFinishedProduct
    {
        $trip = $this->resolveWritableTrip((int) $data['tripId']);
        $finishedProduct = $this->ensureFinishedProductIsActive((int) $data['finishedProductId']);

        $this->ensureFinishedProductMatchesClient($finishedProduct, $trip);
        $this->ensureFinishedProductIsNotInTrip($finishedProduct, $trip);

        try {
            $line = TripFinishedProduct::create([
                'trip_id' => $trip->id,
                'finished_product_id' => $finishedProduct->id,
                'boxes' => (int) $data['boxes'],
                /** El autor sale del usuario autenticado, nunca del body. */
                'registered_by' => $user->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            /** Dos altas simultáneas del mismo producto: el índice único corta la segunda. */
            throw new BadRequestError('El producto terminado ya está en el viaje');
        }

        return $line->load(self::RELATIONS);
    }

    #[Override]
    public function updateTripFinishedProduct(int $id, array $data): TripFinishedProduct
    {
        $line = $this->resolveTripFinishedProduct($id);

        $this->resolveWritableTrip($line->trip_id);

        /** Solo `boxes`: viaje, producto y autor son inmutables. */
        $line->update(['boxes' => (int) $data['boxes']]);

        return $line->load(self::RELATIONS);
    }

    #[Override]
    public function deleteTripFinishedProduct(int $id): TripFinishedProduct
    {
        $line = $this->resolveTripFinishedProduct($id);

        $this->resolveWritableTrip($line->trip_id);

        DB::transaction(function () use ($line) {
            $this->ensureTripKeepsOneLine($line->trip_id);

            $line->delete();
        });

        return $line;
    }

    /**
     * Resolve the trip a line write is aiming at.
     *
     * Reads withTrashed() so a deleted trip answers 400 «El viaje ya fue eliminado» and
     * stays distinguishable from an id that never existed; then refuses any trip that is
     * no longer `pending`: once in route, the cargo already left.
     */
    private function resolveWritableTrip(int $tripId): Trip
    {
        $trip = Trip::withTrashed()->find($tripId);

        if ($trip === null) {
            throw new NotFoundError('El viaje no existe');
        }

        if ($trip->trashed()) {
            throw new BadRequestError('El viaje ya fue eliminado');
        }

        if ($trip->status !== TripStatus::Pending) {
            throw new BadRequestError('Solo se pueden modificar los productos de un viaje pendiente');
        }

        return $trip;
    }

    /**
     * Refuse a finished product that has been soft deleted.
     *
     * Literal copy of `ensureClientIsActive()` (SPEC 36): the form request's exists rule
     * reads the raw table and lets deleted rows through.
     */
    private function ensureFinishedProductIsActive(int $finishedProductId): FinishedProduct
    {
        $finishedProduct = FinishedProduct::withTrashed()->find($finishedProductId);

        if ($finishedProduct === null) {
            throw new NotFoundError('El producto terminado no existe');
        }

        if ($finishedProduct->trashed()) {
            throw new BadRequestError('El producto terminado seleccionado ya fue eliminado');
        }

        return $finishedProduct;
    }

    /**
     * Refuse a finished product packed for another client than the trip's.
     */
    private function ensureFinishedProductMatchesClient(FinishedProduct $finishedProduct, Trip $trip): void
    {
        if ($finishedProduct->client_id !== $trip->client_id) {
            throw new BadRequestError('El producto terminado no pertenece al cliente del viaje');
        }
    }

    /**
     * Refuse a finished product the trip already carries.
     *
     * Duplicates the unique index on purpose, so the common case gets a 400 in Spanish
     * without relying on catching the constraint violation.
     */
    private function ensureFinishedProductIsNotInTrip(FinishedProduct $finishedProduct, Trip $trip): void
    {
        $exists = TripFinishedProduct::query()
            ->where('trip_id', '=', $trip->id)
            ->where('finished_product_id', '=', $finishedProduct->id)
            ->exists();

        if ($exists) {
            throw new BadRequestError('El producto terminado ya está en el viaje');
        }
    }

    /**
     * Refuse to delete the last line of a trip.
     *
     * Must run inside a transaction: the lines are locked before counting, so two
     * simultaneous deletes on a two-line trip cannot both see «two» and leave zero.
     */
    private function ensureTripKeepsOneLine(int $tripId): void
    {
        $lines = TripFinishedProduct::query()
            ->where('trip_id', '=', $tripId)
            ->lockForUpdate()
            ->pluck('id');

        if ($lines->count() <= 1) {
            throw new BadRequestError('El viaje debe tener al menos un producto terminado');
        }
    }

    /**
     * Resolve the line matching the given id.
     */
    private function resolveTripFinishedProduct(int $id): TripFinishedProduct
    {
        $line = TripFinishedProduct::query()->with(self::RELATIONS)->find($id);

        if ($line === null) {
            throw new NotFoundError('La línea de producto no existe');
        }

        return $line;
    }
}
