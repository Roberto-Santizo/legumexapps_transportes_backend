<?php

namespace App\Services\TripCost;

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Interfaces\Trip\TripServiceInterface;
use App\Interfaces\TripCost\TripCostServiceInterface;
use App\Models\FuelPrice;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\TripFuel;
use App\Models\User;
use Override;

class TripCostService implements TripCostServiceInterface
{
    /**
     * The hours a month is worth when prorating the salary and the insurance: 30 × 24.
     *
     * The only place that knows the convention. The month is the real month and not a
     * working day, because this domain does not model shifts: a trip driven at dawn
     * cannot cost three times the same trip driven at noon. Moving it to days or to a
     * working month is one line here, in another spec.
     */
    public const MONTHLY_HOURS = 720;

    /**
     * The trip domain resolves both the trip and the reading scope of SPEC 24.
     *
     * Injected by constructor —the by method parameter rule is the controller's alone—
     * so this service never rewrites that matrix. Third repetition of the pattern, after
     * TripPositionService (SPEC 26), TripFuelService and TripExpenseService.
     */
    public function __construct(private TripServiceInterface $tripService) {}

    #[Override]
    public function getTripCost(User $user, int $tripId): array
    {
        $trip = $this->resolveCostableTrip($user, $tripId);

        $traveledHours = $trip->traveled_hours === null ? null : (float) $trip->traveled_hours;

        $fuel = $this->resolveFuel($trip);
        $expenses = $this->resolveExpenses($trip);
        $pilot = ['monthlySalary' => null, 'subtotal' => 0.0];
        $vehicle = ['monthlyInsuranceCost' => null, 'subtotal' => 0.0];

        return [
            'trip' => $trip,
            'traveledHours' => $traveledHours,
            'fuel' => $fuel,
            'expenses' => $expenses,
            'pilot' => $pilot,
            'vehicle' => $vehicle,
            /**
             * La suma de los cuatro subtotales YA redondeados, nunca el redondeo de una
             * suma en crudo: el desglose tiene que cuadrar con el total a la vista.
             */
            'totalCost' => round($fuel['subtotal'] + $expenses['subtotal'] + $pilot['subtotal'] + $vehicle['subtotal'], 2),
        ];
    }

    /**
     * Price every **confirmed** fuel load of the trip at the price in force on its date.
     *
     * Only confirmed loads are quoted —the same criterion as `totalFuelGallons`, so the
     * cost never contradicts `TripResource`— and a confirmed load always carries its
     * `loaded_at`, so there is no such thing as a load with no date to look a price up
     * for. An unconfirmed one neither adds gallons nor shows up in the breakdown.
     *
     * Two queries at most, whatever the number of loads: one for the loads and one for
     * every price of the types actually present. The pairing of each `loaded_at` with
     * its price happens **in PHP**, never as a subquery per load, which on the table
     * that can grow the most would be a textbook N+1.
     *
     * @return array{gallons: float, byType: list<array{fuelType: string, gallons: float, pricePerGallon: float|null, amount: float}>, subtotal: float}
     */
    private function resolveFuel(Trip $trip): array
    {
        $loads = TripFuel::query()
            ->select(['gallons', 'fuel_type', 'loaded_at'])
            ->where('trip_id', '=', $trip->id)
            ->whereNotNull('loaded_at')
            ->orderBy('id')
            ->get();

        if ($loads->isEmpty()) {
            return ['gallons' => 0.0, 'byType' => [], 'subtotal' => 0.0];
        }

        $prices = $this->pricesByType($loads->pluck('fuel_type.value')->unique()->values()->all());

        /**
         * Se agrupa por tipo pero se multiplica por carga: dos cargas del mismo tipo a
         * ambos lados de un cambio de precio se cotizan a precios distintos y caen en un
         * solo elemento de byType con sus importes ya sumados.
         */
        $groups = [];

        foreach ($loads as $load) {
            $type = $load->fuel_type->value;
            $gallons = (float) $load->gallons;
            $price = $this->priceAt($prices[$type] ?? [], $load->loaded_at->getTimestamp());

            $groups[$type] ??= ['gallons' => 0.0, 'pricedGallons' => 0.0, 'amount' => 0.0];
            $groups[$type]['gallons'] += $gallons;

            /** Sin precio capturado para esa fecha la carga aporta 0.00, pero sus galones sí suman. */
            if ($price !== null) {
                $groups[$type]['pricedGallons'] += $gallons;
                $groups[$type]['amount'] += $gallons * $price;
            }
        }

        $byType = [];
        $subtotal = 0.0;
        $gallons = 0.0;

        foreach ($groups as $type => $group) {
            $amount = round($group['amount'], 2);

            $byType[] = [
                'fuelType' => $type,
                'gallons' => round($group['gallons'], 2),
                /**
                 * El precio medio ponderado de las cargas que SÍ resolvieron precio, para que
                 * galones por precio siga cuadrando con el importe. Con un único precio vigente
                 * es exactamente ese precio; con ninguna carga cotizada es null, que es la señal
                 * de que al tipo le falta historial de precios para esas fechas.
                 */
                'pricePerGallon' => $group['pricedGallons'] > 0.0 ? round($amount / $group['pricedGallons'], 2) : null,
                'amount' => $amount,
            ];

            $gallons += $group['gallons'];
            $subtotal += $amount;
        }

        return [
            'gallons' => round($gallons, 2),
            'byType' => $byType,
            /** Suma de importes ya redondeados, como el total suma los subtotales ya redondeados. */
            'subtotal' => round($subtotal, 2),
        ];
    }

    /**
     * Add up the **confirmed** travel allowances of the trip, in one aggregate query.
     *
     * Only the confirmed ones count —`received_at IS NOT NULL`—, the same criterion as
     * `totalExpensesAmount` in `TripResource`, so the two numbers always agree for the
     * same trip: what has not been handed over yet has not been spent yet.
     *
     * One query whatever the number of rows: the count and the sum travel together and
     * nothing is hydrated into a model, because no allowance is painted here.
     *
     * @return array{count: int, subtotal: float}
     */
    private function resolveExpenses(Trip $trip): array
    {
        $aggregate = TripExpense::query()
            ->where('trip_id', '=', $trip->id)
            ->whereNotNull('received_at')
            ->selectRaw('count(*) as rows_count, coalesce(sum(amount), 0) as rows_amount')
            ->first();

        return [
            'count' => (int) ($aggregate?->rows_count ?? 0),
            'subtotal' => round((float) ($aggregate?->rows_amount ?? 0), 2),
        ];
    }

    /**
     * Load every captured price of the given fuel types, oldest first, in one query.
     *
     * The `status` of the row is deliberately ignored: an `inactive` row is precisely
     * the price that was in force back then, and filtering by `active` would quote every
     * date at today's price.
     *
     * @param  list<string>  $types
     * @return array<string, list<array{at: int, price: float}>>
     */
    private function pricesByType(array $types): array
    {
        $prices = [];

        FuelPrice::query()
            ->select(['fuel_type', 'price', 'created_at', 'id'])
            ->whereIn('fuel_type', $types)
            ->orderBy('created_at')
            /** Desempate entre dos capturas del mismo segundo, como la bitácora de SPEC 11. */
            ->orderBy('id')
            ->get()
            ->each(function (FuelPrice $price) use (&$prices): void {
                $prices[$price->fuel_type->value][] = [
                    'at' => $price->created_at->getTimestamp(),
                    'price' => (float) $price->price,
                ];
            });

        return $prices;
    }

    /**
     * Pick the last price captured at or before the given moment.
     *
     * `fuel_prices.created_at` is when the administrator **captured** the price and not
     * when it started ruling —SPEC 06 published no validity column—, so a load made
     * before the first capture of its type has no price at all and answers `null`.
     *
     * @param  list<array{at: int, price: float}>  $prices  Ascending by capture moment.
     */
    private function priceAt(array $prices, int $moment): ?float
    {
        $resolved = null;

        foreach ($prices as $price) {
            if ($price['at'] > $moment) {
                break;
            }

            $resolved = $price['price'];
        }

        return $resolved;
    }

    /**
     * Resolve the trip somebody is asking the cost of.
     *
     * Two rules of its own and the rest delegated on getTripById(), which already throws
     * 404 for a trip that does not exist **or has been deleted** —this is a read and it
     * follows `GET /{trip}`— and 403 outside the caller's company.
     *
     * The pilot is vetoed first and unconditionally, not even for his own trip, exactly
     * as with the stops of SPEC 27 and the track of SPEC 26: the breakdown would hand
     * him his own monthly salary. The state check goes last, so an `in_route` trip of
     * another company answers 403 and not 400.
     */
    private function resolveCostableTrip(User $user, int $tripId): Trip
    {
        if ($user->role === UserRole::Pilot) {
            throw new ForbiddenError('No tienes permisos para consultar el costo de un viaje');
        }

        $trip = $this->tripService->getTripById($user, $tripId);

        /**
         * Un viaje en curso no tiene costo parcial: su duración sería `now() − start_date`
         * y subiría en cada refresco. Se prefiere un único número, siempre completo.
         */
        if ($trip->status !== TripStatus::Finished) {
            throw new BadRequestError('El costo solo está disponible para viajes finalizados');
        }

        return $trip;
    }
}
