<?php

namespace App\Interfaces\PushNotification;

use Throwable;

interface PushNotificationServiceInterface
{
    /**
     * Send one notification to every given device token and return the dead ones.
     *
     * An empty list never reaches the provider and returns []. The implementation
     * splits the tokens into batches the provider accepts in a single call.
     *
     * Only tokens the provider reports as unregistered or invalid are returned, so
     * the caller can delete them; a per-token failure of any other kind (quota, an
     * internal error) is not returned, because it says nothing about the token.
     *
     * @param  list<string>  $tokens  Device tokens, sent as-is.
     * @param  string  $title  Visible title of the notification.
     * @param  string  $body  Visible body of the notification.
     * @param  array<string, string>  $data  Silent payload for the app; values must be strings.
     * @return list<string> Tokens the provider rejected as unregistered or invalid.
     *
     * @throws Throwable when a failure hits a whole batch or the whole call (credentials, network).
     */
    public function send(array $tokens, string $title, string $body, array $data): array;
}
