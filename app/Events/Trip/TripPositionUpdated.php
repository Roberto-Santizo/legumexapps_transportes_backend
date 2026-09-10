<?php

namespace App\Events\Trip;

use App\Models\TripPosition;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new point of a trip's live track, pushed to whoever is watching its map.
 *
 * The first class under `app/Events/` and the first outbound message of the project
 * that is not an HTTP request: until now the API only answered or called Google.
 *
 * `ShouldBroadcastNow`, **not** `ShouldBroadcast`: the project has
 * `QUEUE_CONNECTION=database` but no worker declared anywhere, so a queued event
 * would simply never arrive. The cost is a few extra milliseconds inside the pilot's
 * POST, and the day there is a worker it is a one line change.
 */
class TripPositionUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     *
     * `pilotName` travels by constructor instead of being read from
     * `$position->pilot->name`: the service already holds the trip with its pilot
     * loaded, so the event does not fire an extra query per point.
     */
    public function __construct(public TripPosition $position, public string $pilotName) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * A private channel per trip, and no fleet wide one: watching three trips means
     * subscribing to three channels. It is not a presence channel either — nobody
     * needs to know who else is looking at the map.
     *
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('trips.'.$this->position->trip_id)];
    }

    /**
     * The event name the client listens for.
     *
     * Aliased so the frontend listens to '.trip.position.updated' —with the leading
     * dot— instead of the PHP namespace of this class.
     */
    public function broadcastAs(): string
    {
        return 'trip.position.updated';
    }

    /**
     * Get the data to broadcast.
     *
     * Explicit on purpose: without it the whole serialized model would travel, with
     * `created_at`, `updated_at` and whatever column is added later. The payload is
     * contract, so it is these six keys and no more.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'tripId' => $this->position->trip_id,
            'latitude' => $this->position->latitude,
            'longitude' => $this->position->longitude,
            'recordedAt' => $this->position->recorded_at?->format('d-m-Y h:i:s A'),
            'pilotId' => $this->position->pilot_id,
            'pilotName' => $this->pilotName,
        ];
    }
}
