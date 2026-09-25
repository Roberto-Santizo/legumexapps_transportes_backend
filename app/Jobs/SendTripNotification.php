<?php

namespace App\Jobs;

use App\Enums\TripNotificationType;
use App\Interfaces\TripNotification\TripNotificationServiceInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Push a trip event to its recipients (SPEC 35).
 *
 * Dispatched with dispatchAfterResponse(): it runs in the same PHP process once the
 * response has been sent, so it needs no worker and does not depend on
 * QUEUE_CONNECTION. It carries only the trip id and the type — the service reloads
 * the trip, so it reads the committed state and no model gets serialized.
 */
class SendTripNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $tripId, public TripNotificationType $type) {}

    public function handle(TripNotificationServiceInterface $tripNotificationService): void
    {
        $tripNotificationService->notify($this->tripId, $this->type);
    }
}
