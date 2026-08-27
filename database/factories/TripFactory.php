<?php

namespace Database\Factories;

use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\Client;
use App\Models\DeparturePoint;
use App\Models\Location;
use App\Models\ShippingLine;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Trip>
 */
class TripFactory extends Factory
{
    /**
     * A fixed encoded polyline, the one Google's own documentation uses as an example.
     *
     * It decodes to three pairs, so a test can assert `points` without carrying a
     * hundred coordinates around.
     */
    public const POLYLINE = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';

    /**
     * Define the model's default state.
     *
     * A trip nobody has taken yet: `pending`, with the three crew columns null.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** Siempre a futuro, y con el embarque después de la recolección. */
        $recolectionDate = fake()->dateTimeBetween('+2 days', '+20 days');

        return [
            'order' => Trip::normalizeReference('ord-'.date('Y').'-'.fake()->unique()->numerify('####')),
            'client_id' => Client::factory(),
            'shipping_line_id' => ShippingLine::factory(),
            'departure_point_id' => DeparturePoint::factory(),
            /** El destino de un viaje es siempre un puerto activo: la regla la exige el service. */
            'location_id' => Location::factory()->port()->active(),
            'destination' => fake()->randomElement([
                'Rotterdam, Países Bajos', 'Miami, Estados Unidos', 'Algeciras, España',
                'Hamburgo, Alemania', 'Long Beach, Estados Unidos',
            ]),
            'container' => Trip::normalizeReference(fake()->bothify('???? ###### #')),
            'transport' => fake()->randomElement(['Rastra 40 pies', 'Furgón refrigerado', 'Cabezal con plataforma']),
            'recolection_date' => $recolectionDate,
            'ship_date' => fake()->dateTimeBetween($recolectionDate, '+30 days'),
            'start_date' => null,
            'end_date' => null,
            'polyline' => self::POLYLINE,
            'observations' => fake()->sentence(),
            'status' => TripStatus::Pending,
            'pilot_id' => null,
            'vehicle_id' => null,
            'assigned_by' => null,
            'registered_by' => User::factory()->state(['role' => UserRole::Administrator]),
        ];
    }

    /**
     * Indicate that a carrier company has taken the trip.
     *
     * The three crew columns are filled together and all of them belong to the same
     * company: the pilot is linked through `carrier_pilots`, the vehicle is owned by
     * that carrier and `assigned_by` is its owner.
     */
    public function assigned(): static
    {
        return $this->state(function (array $attributes) {
            $carrier = Carrier::factory()->create();
            $pilot = User::factory()->create(['role' => UserRole::Pilot]);
            $carrier->pilots()->attach($pilot);

            return [
                'pilot_id' => $pilot->id,
                'vehicle_id' => Vehicle::factory()->create(['carrier_id' => $carrier->id])->id,
                'assigned_by' => $carrier->user_id,
            ];
        });
    }

    /**
     * Indicate that the assigned pilot already started the trip.
     *
     * Built on top of `assigned()`: a trip cannot be in route without a crew.
     */
    public function inRoute(): static
    {
        return $this->assigned()->state(fn (array $attributes) => [
            'status' => TripStatus::InRoute,
            'start_date' => now()->subHours(4),
        ]);
    }

    /**
     * Indicate that the assigned pilot already closed the trip.
     */
    public function finished(): static
    {
        return $this->inRoute()->state(fn (array $attributes) => [
            'status' => TripStatus::Finished,
            'end_date' => now(),
        ]);
    }

    /**
     * Indicate that the trip has already been deleted.
     *
     * The row stays in the database — and so does its foreign key to the client and to
     * the shipping line, which is why both guards check `withTrashed()`.
     */
    public function trashed(): static
    {
        return $this->state(fn (array $attributes) => [
            'deleted_at' => now(),
        ]);
    }
}
