<?php

namespace App\Interfaces\TripNotification;

use App\Enums\TripNotificationType;

interface TripNotificationServiceInterface
{
    /**
     * Push the given trip event to the devices of whoever should hear about it.
     *
     * The trip is reloaded from its id, so the state read is the one already committed.
     * Recipients by type:
     *  - `trip.assigned`: the tokens of the trip's `pilot_id`;
     *  - `trip.started` / `trip.finished`: the tokens of every user whose role is not
     *    `pilot` and who is either not a `carrier` or the owner of the company of
     *    `assigned_by`.
     *
     * Does nothing when the trip does not exist, is deleted or no recipient has a token.
     * Tokens the provider rejects are deleted with a single query.
     *
     * Never throws: any failure is logged with Log::error and swallowed, because the
     * trip action it follows has already answered the client.
     */
    public function notify(int $tripId, TripNotificationType $type): void;
}
