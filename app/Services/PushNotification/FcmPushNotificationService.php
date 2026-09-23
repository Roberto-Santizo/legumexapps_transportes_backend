<?php

namespace App\Services\PushNotification;

use App\Interfaces\PushNotification\PushNotificationServiceInterface;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\SendReport;
use Override;

/**
 * Push notifications through Firebase Cloud Messaging (SPEC 35).
 *
 * The only file of the project that mentions Kreait: the rest of the code talks to
 * PushNotificationServiceInterface and never learns the provider's name.
 */
final class FcmPushNotificationService implements PushNotificationServiceInterface
{
    /**
     * Maximum number of tokens FCM accepts in a single multicast call.
     */
    public const BATCH_SIZE = 500;

    public function __construct(private readonly Messaging $messaging) {}

    #[Override]
    public function send(array $tokens, string $title, string $body, array $data): array
    {
        if ($tokens === []) {
            return [];
        }

        $message = CloudMessage::new()
            ->withNotification(Notification::create($title, $body))
            ->withData($data);

        $rejectedTokens = [];

        foreach (array_chunk($tokens, self::BATCH_SIZE) as $batch) {
            $report = $this->messaging->sendMulticast($message, $batch);

            $rejectedTokens = [
                ...$rejectedTokens,
                ...$report->filter(fn (SendReport $item): bool => $this->isDeadToken($item))
                    ->map(fn (SendReport $item): string => $item->target()->value()),
            ];
        }

        return $rejectedTokens;
    }

    /**
     * Whether FCM rejected the token itself, not the delivery.
     *
     * Kreait turns UNREGISTERED (HTTP 404) into NotFound and INVALID_ARGUMENT
     * (HTTP 400) into InvalidMessage; anything else — quota, a server error — is
     * transient and leaves the token alive.
     */
    private function isDeadToken(SendReport $item): bool
    {
        $error = $item->error();

        return $error instanceof NotFound || $error instanceof InvalidMessage;
    }
}
