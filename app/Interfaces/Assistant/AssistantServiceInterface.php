<?php

namespace App\Interfaces\Assistant;

use App\Models\User;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * The chat with the dashboard assistant.
 *
 * The first domain of the project whose response is not a JSON envelope: the service
 * returns the SDK's streamable response and the controller turns it into the Vercel
 * AI SDK protocol (`text/event-stream`). Nothing is persisted: the memory lives in
 * the client, which sends the previous turns with every request. Once the stream is
 * open, provider errors are reported inside it as an `error` part, never as a 5xx.
 */
interface AssistantServiceInterface
{
    /**
     * Answer one turn, streaming, with the previous turns as context.
     *
     * `$history` is the conversation so far as the client kept it, oldest first and
     * without `$prompt`; it is handed to the model as is and never stored.
     *
     * @param  list<array{role: string, content: string}>  $history
     */
    public function chat(User $user, string $prompt, array $history = []): StreamableAgentResponse;
}
