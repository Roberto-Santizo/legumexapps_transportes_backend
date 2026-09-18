<?php

namespace App\Interfaces\Assistant;

use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Models\User;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * The chat with the dashboard assistant.
 *
 * The first domain of the project whose response is not a JSON envelope: the service
 * returns the SDK's streamable response and the controller turns it into the Vercel
 * AI SDK protocol (`text/event-stream`). Everything that can fail with a status code
 * —conversation missing, conversation of someone else— fails **before** the stream
 * opens, so it still comes out through `ResponseHandler`. Once the stream is open,
 * provider errors are reported inside it as an `error` part, never as a 5xx.
 */
interface AssistantServiceInterface
{
    /**
     * Answer one turn of a conversation, streaming.
     *
     * Without `$conversationId` a new conversation is opened for the user, titled with
     * the prompt itself; with it the conversation is resumed and the previous turns
     * are handed to the model by the SDK. Either way the returned response already
     * carries `conversationId`, so the caller can expose it before iterating.
     *
     * @throws NotFoundError when the conversation does not exist
     * @throws ForbiddenError when the conversation belongs to another user
     */
    public function chat(User $user, string $prompt, ?string $conversationId): StreamableAgentResponse;
}
