<?php

namespace App\Services\TripCost;

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Errors\BadRequestError;
use App\Errors\ForbiddenError;
use App\Interfaces\Trip\TripServiceInterface;
use App\Interfaces\TripCost\TripCostServiceInterface;
use App\Models\Trip;
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

        $fuel = ['gallons' => 0.0, 'byType' => [], 'subtotal' => 0.0];
        $expenses = ['count' => 0, 'subtotal' => 0.0];
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
