<?php

namespace Tests\Doubles;

use App\Interfaces\PushNotification\PushNotificationServiceInterface;
use Override;
use RuntimeException;

/**
 * A push contract implementation that never reaches Firebase.
 *
 * Bound globally in tests/Pest.php: kreait speaks HTTP through its own Guzzle
 * client, so Http::preventStrayRequests() would not stop a real send.
 *
 * It records every call, rejects the tokens it was told to reject and can be
 * made to throw, as a provider that is down would.
 */
class InMemoryPushNotificationService implements PushNotificationServiceInterface
{
    /**
     * Every send() that reached the double, in order.
     *
     * @var list<array{tokens: list<string>, title: string, body: string, data: array<string, string>}>
     */
    public array $sent = [];

    /**
     * @param  list<string>  $rejectedTokens  Tokens reported back as unregistered or invalid.
     * @param  bool  $failing  Makes send() throw, as missing credentials or a network error would.
     */
    public function __construct(
        public array $rejectedTokens = [],
        public bool $failing = false,
    ) {}

    #[Override]
    public function send(array $tokens, string $title, string $body, array $data): array
    {
        if ($this->failing) {
            throw new RuntimeException('Firebase no está disponible');
        }

        if ($tokens === []) {
            return [];
        }

        $this->sent[] = ['tokens' => $tokens, 'title' => $title, 'body' => $body, 'data' => $data];

        return array_values(array_intersect($tokens, $this->rejectedTokens));
    }
}
