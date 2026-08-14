<?php

namespace App\Interfaces\FreightRate;

use App\Models\FreightRate;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface FreightRateServiceInterface
{
    /**
     * List the freight rates, never paginated.
     *
     * The listing is read as a whole price table —every band of a pair, in order—, not
     * as a history someone pages through, so a limit is not accepted and the complete
     * collection always comes back. Deleted rates are out of it. The zone, the product
     * and the registering user travel eager loaded, so labelling the rows costs no
     * extra query per row.
     *
     * @param  array{zoneId?: string|null}  $filters
     *                                                zoneId: narrows the table to a single zone. A non numeric value is ignored
     *                                                instead of failing, and a numeric one matching no zone yields an empty list.
     * @return Collection<int, FreightRate>
     */
    public function getFreightRates(array $filters): Collection;

    /**
     * Return the rate matching the given id, deleted ones included.
     *
     * The shared guard of the three actions addressed by id. It resolves with the
     * trashed rows in scope on purpose: without them a second DELETE could only ever
     * be a 404, when what happened is that the rate was already taken down. Throws a
     * NotFoundError when the id never existed and a BadRequestError when the row is
     * there but already deleted.
     */
    public function getFreightRateById(int $id): FreightRate;

    /**
     * Register a new rate, ruling from the given fuel price upwards.
     *
     * The zone and the product must both be active: quoting a pair that cannot be
     * quoted is refused with a BadRequestError. The band is refused with the same
     * error when a live rate already holds that same combination of zone, product,
     * fuel type and fuel_min — the only uniqueness rule of the domain, kept in the
     * service and deliberately not backed by a unique index, so a deleted rate frees
     * its fuel_min again.
     *
     * @param  array{zoneId: int, productId: int, fuelType: string, fuelMin: float, pricePerPound: float}  $data
     *                                                                                                            fuelMin travels in GTQ per gallon and pricePerPound in GTQ per pound;
     *                                                                                                            registered_by comes from the given user, never from the body.
     */
    public function create(User $user, array $data): FreightRate;

    /**
     * Update the given fields on the row matching the given id.
     *
     * Only the given keys are touched and registered_by is never rewritten: it keeps
     * pointing at whoever registered the rate. The zone and the product are checked to
     * be active even when the payload only moves the price — a rate is not edited while
     * its pair cannot be quoted — and a fuel_min that moves is checked for availability
     * ignoring this same row. An empty payload is a no-op, not an error. Throws the
     * same errors as the guard, plus a BadRequestError for either business rule.
     *
     * @param  array{zoneId?: int, productId?: int, fuelType?: string, fuelMin?: float, pricePerPound?: float}  $data
     */
    public function update(int $id, array $data): FreightRate;

    /**
     * Take the row matching the given id down.
     *
     * This is a real soft delete: deleted_at is stamped and the row leaves both the
     * listing and the quoting, while staying in the table. Unlike the logical delete
     * of zones and products it is NOT idempotent — a second call surfaces the guard's
     * BadRequestError, which is information and not a client mistake. It works on a
     * rate whose zone or product went inactive: deleting is never blocked.
     */
    public function destroy(int $id): FreightRate;

    /**
     * Resolve the rate applying to a destination point and return the quote.
     *
     * A pure read: nothing is created, edited or stamped. The fuel price is never taken
     * from the caller — it is read from the FuelPrice in effect for the requested type,
     * so nobody quotes at the price that suits them.
     *
     * The point is turned into a zone by the zone service, the product is checked to be
     * active, and among the live bands of that zone, product and fuel type the one with
     * the highest fuel_min not above the current fuel price wins; when the fuel is
     * cheaper than every band, the lowest one does. Band selection itself never fails.
     *
     * Throws a NotFoundError when no zone contains the point, and a BadRequestError —
     * each with its own message — when the product is inactive, when the fuel type has
     * no price in effect, or when the pair has no rate quoted at all.
     *
     * @param  array{lat: float, lng: float, productId: int, fuelType: string, pounds?: float|null}  $filters
     *                                                                                                         pounds: optional weight of the load; when it arrives the total comes back
     *                                                                                                         with it, and when it does not both travel null.
     * @return array{rate: FreightRate, currentFuelPrice: string, pounds: float|null, total: float|null}
     *                                                                                                   rate: the applied band, with its zone and product loaded;
     *                                                                                                   currentFuelPrice: the price of the FuelPrice in effect, in GTQ per gallon;
     *                                                                                                   total: pounds times the full six decimal rate, rounded only at the end.
     */
    public function quote(array $filters): array;
}
