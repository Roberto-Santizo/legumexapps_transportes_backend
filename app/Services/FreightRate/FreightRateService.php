<?php

namespace App\Services\FreightRate;

use App\Errors\BadRequestError;
use App\Errors\NotFoundError;
use App\Interfaces\FreightRate\FreightRateServiceInterface;
use App\Interfaces\Zone\ZoneServiceInterface;
use App\Models\FreightRate;
use App\Models\Product;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Collection;
use LogicException;
use Override;

class FreightRateService implements FreightRateServiceInterface
{
    /**
     * The spatial query stays in the zone service: nothing here ever writes `ST_`.
     *
     * Injected through the constructor, like the storage contracts, because it is a
     * collaborator of the whole class and not of a single action.
     */
    public function __construct(
        private readonly ZoneServiceInterface $zoneService,
    ) {}

    #[Override]
    public function getFreightRates(array $filters): Collection
    {
        $query = FreightRate::query()->with('zone', 'product', 'registeredBy');

        /** Un zoneId que no sea numérico se ignora en vez de vaciar la tabla de precios. */
        if (isset($filters['zoneId']) && is_numeric($filters['zoneId'])) {
            $query->where('zone_id', '=', (int) $filters['zoneId']);
        }

        /** La tabla de precios se lee por tipo de combustible y, dentro de cada uno, por banda. */
        return $query->orderBy('fuel_type')->orderBy('fuel_min')->get();
    }

    #[Override]
    public function getFreightRateById(int $id): FreightRate
    {
        /** Con las borradas a la vista: sin ellas, el segundo DELETE solo podría ser un 404. */
        $rate = FreightRate::withTrashed()->with('zone', 'product', 'registeredBy')->find($id);

        if ($rate === null) {
            throw new NotFoundError('La tarifa no existe');
        }

        if ($rate->trashed()) {
            throw new BadRequestError('La tarifa ya fue eliminada');
        }

        return $rate;
    }

    #[Override]
    public function create(User $user, array $data): FreightRate
    {
        $zoneId = (int) $data['zoneId'];
        $productId = (int) $data['productId'];
        $fuelType = $data['fuelType'];
        $fuelMin = $this->fuelMinValue($data['fuelMin']);

        $this->ensureZoneAndProductAreActive($zoneId, $productId);
        $this->ensureFuelMinIsAvailable($zoneId, $productId, $fuelType, $fuelMin);

        $rate = FreightRate::create([
            'zone_id' => $zoneId,
            'product_id' => $productId,
            'fuel_type' => $fuelType,
            'fuel_min' => $fuelMin,
            'price_per_pound' => $data['pricePerPound'],
            /** Sale del usuario autenticado, nunca del body. */
            'registered_by' => $user->id,
        ]);

        /** Se relee para que la fila vuelva con su zona y su producto resueltos. */
        return $this->getFreightRateById($rate->id);
    }

    #[Override]
    public function update(int $id, array $data): FreightRate
    {
        $rate = $this->getFreightRateById($id);

        /** Lo que no llega se toma de la propia fila: las dos reglas miran el par completo. */
        $zoneId = isset($data['zoneId']) ? (int) $data['zoneId'] : $rate->zone_id;
        $productId = isset($data['productId']) ? (int) $data['productId'] : $rate->product_id;
        $fuelType = $data['fuelType'] ?? $rate->fuel_type->value;
        $fuelMin = isset($data['fuelMin']) ? $this->fuelMinValue($data['fuelMin']) : $rate->fuel_min;

        /** Se revalida aunque el PATCH solo mueva el precio: una tarifa no se edita si su par ya no es cotizable. */
        $this->ensureZoneAndProductAreActive($zoneId, $productId);

        /** Se ignora la propia fila: reenviar su mismo fuel_min no puede chocar consigo misma. */
        $this->ensureFuelMinIsAvailable($zoneId, $productId, $fuelType, $fuelMin, $rate->id);

        $rate->zone_id = $zoneId;
        $rate->product_id = $productId;
        $rate->fuel_type = $fuelType;
        $rate->fuel_min = $fuelMin;

        if (isset($data['pricePerPound'])) {
            $rate->price_per_pound = $data['pricePerPound'];
        }

        /** registered_by no se reescribe: sigue apuntando a quien dio de alta la tarifa. */
        $rate->save();

        return $this->getFreightRateById($rate->id);
    }

    #[Override]
    public function destroy(int $id): FreightRate
    {
        /** La guarda es la que hace que el segundo DELETE sea un 400 y no un 404. */
        $rate = $this->getFreightRateById($id);

        /** Borrar nunca se bloquea, ni siquiera con la zona o el producto inactivos. */
        $rate->delete();

        return $rate;
    }

    #[Override]
    public function quote(array $filters): array
    {
        throw new LogicException('Pendiente: Paso 6 de la SPEC 09.');
    }

    /**
     * Refuse a pair that cannot be quoted.
     *
     * A rate lives on a zone and a product that are both published; either of them
     * taken down freezes its rates, editing included. Throws a BadRequestError naming
     * which of the two is inactive, so the way out is obvious.
     */
    private function ensureZoneAndProductAreActive(int $zoneId, int $productId): void
    {
        $zoneIsActive = Zone::query()->whereKey($zoneId)->where('status', '=', true)->exists();

        if (! $zoneIsActive) {
            throw new BadRequestError('La zona seleccionada no está activa');
        }

        $productIsActive = Product::query()->whereKey($productId)->where('status', '=', true)->exists();

        if (! $productIsActive) {
            throw new BadRequestError('El producto seleccionado no está activo');
        }
    }

    /**
     * Refuse a band another live rate of the same pair already holds.
     *
     * The single uniqueness rule of the domain, and deliberately not backed by a unique
     * index: with soft deletes, an index would block a fuel_min that was deleted for
     * good. Only live rows are looked at, so a deleted rate frees its band again.
     * Throws a BadRequestError when the band is taken.
     *
     * @param  int|null  $ignoreId  row allowed to hold the band, so an update can resend its own.
     */
    private function ensureFuelMinIsAvailable(
        int $zoneId,
        int $productId,
        string $fuelType,
        string $fuelMin,
        ?int $ignoreId = null,
    ): void {
        $exists = FreightRate::query()
            ->where('zone_id', '=', $zoneId)
            ->where('product_id', '=', $productId)
            ->where('fuel_type', '=', $fuelType)
            ->where('fuel_min', '=', $fuelMin)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw new BadRequestError('Ya existe una tarifa para esa zona, ese producto y ese combustible desde ese precio');
        }
    }

    /**
     * Render a fuel_min the way the column stores it, with exactly two decimals.
     *
     * Comparing what arrives against what the table holds only works when both have the
     * same shape: without this, a 30.005 that PostgreSQL rounds to 30.01 would look
     * available and slip past the uniqueness check.
     */
    private function fuelMinValue(int|float|string $fuelMin): string
    {
        return number_format((float) $fuelMin, 2, '.', '');
    }
}
