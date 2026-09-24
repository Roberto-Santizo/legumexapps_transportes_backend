<?php

namespace App\Services\TripNotification;

use App\Enums\TripNotificationType;
use App\Enums\UserRole;
use App\Interfaces\PushNotification\PushNotificationServiceInterface;
use App\Interfaces\TripNotification\TripNotificationServiceInterface;
use App\Models\Trip;
use App\Models\UserDeviceToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Override;
use Throwable;

/**
 * Decides who hears about a trip event and what they read (SPEC 35).
 *
 * Reads `Trip` directly and does **not** inject TripServiceInterface: TripService
 * dispatches the job that ends here, and the reverse contract would close a cycle in
 * the container — the precedent of closeOpenTimeout() touching `TripTimeout`.
 */
class TripNotificationService implements TripNotificationServiceInterface
{
    public function __construct(private readonly PushNotificationServiceInterface $pushNotificationService) {}

    #[Override]
    public function notify(int $tripId, TripNotificationType $type): void
    {
        try {
            $trip = Trip::query()->with(['location', 'pilot', 'assignedBy'])->find($tripId);

            if ($trip === null) {
                return;
            }

            $tokens = $this->recipientTokens($trip, $type);

            if ($tokens === []) {
                return;
            }

            [$title, $body] = $this->message($trip, $type);

            $rejectedTokens = $this->pushNotificationService->send($tokens, $title, $body, [
                'type' => $type->value,
                'tripId' => (string) $trip->id,
            ]);

            if ($rejectedTokens !== []) {
                UserDeviceToken::query()->whereIn('token', $rejectedTokens)->delete();
            }
        } catch (Throwable $th) {
            Log::error('No se pudo enviar la notificación del viaje', [
                'tripId' => $tripId,
                'type' => $type->value,
                'exception' => $th->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the device tokens of the recipients of the event.
     *
     * The assignment goes to the assigned pilot only. The start and the finish go to
     * every non-pilot user, except the carriers that do not own the company that took
     * the trip: the scope of SPEC 24 answers them 403 on the trip, so they do not hear
     * about it either. The company comes from `assigned_by` through currentCarrier();
     * without one, no carrier at all is notified.
     *
     * @return list<string>
     */
    private function recipientTokens(Trip $trip, TripNotificationType $type): array
    {
        if ($type === TripNotificationType::Assigned) {
            if ($trip->pilot_id === null) {
                return [];
            }

            return UserDeviceToken::query()->where('user_id', $trip->pilot_id)->pluck('token')->all();
        }

        $ownerId = $trip->assignedBy?->currentCarrier()?->user_id;

        return UserDeviceToken::query()
            ->join('users', 'users.id', '=', 'user_device_tokens.user_id')
            ->where('users.role', '<>', UserRole::Pilot->value)
            ->where(function (Builder $query) use ($ownerId): void {
                $query->where('users.role', '<>', UserRole::Carrier->value)
                    ->when($ownerId !== null, fn (Builder $query) => $query->orWhere('users.id', $ownerId));
            })
            ->pluck('user_device_tokens.token')
            ->all();
    }

    /**
     * Title and body of the notification, in Spanish.
     *
     * The collection date goes in the same `d-m-Y h:i:s A` format TripResource uses,
     * so the pilot reads on the notification exactly what the app shows on the trip.
     *
     * @return array{0: string, 1: string}
     */
    private function message(Trip $trip, TripNotificationType $type): array
    {
        $destination = $trip->location?->name;
        $pilot = $trip->pilot?->name;

        return match ($type) {
            TripNotificationType::Assigned => [
                'Nuevo viaje asignado',
                "Tienes un nuevo viaje asignado con fecha de recolección: {$trip->recolection_date?->format('d-m-Y h:i:s A')}",
            ],
            TripNotificationType::Started => ['Viaje iniciado', "Orden {$trip->order} · {$pilot} en ruta a {$destination}"],
            TripNotificationType::Finished => ['Viaje finalizado', "Orden {$trip->order} · {$pilot} llegó a {$destination}"],
        };
    }
}
