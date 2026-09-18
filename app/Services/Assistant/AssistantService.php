<?php

namespace App\Services\Assistant;

use App\Ai\Agents\DashboardAssistant;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Assistant\AssistantServiceInterface;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Override;

class AssistantService implements AssistantServiceInterface
{
    /** Longest title a conversation gets: the opening prompt, cut on a word boundary. */
    private const int TITLE_LENGTH = 100;

    public function __construct(
        private readonly ConversationStore $conversations,
    ) {}

    #[Override]
    public function chat(User $user, string $prompt, ?string $conversationId): StreamableAgentResponse
    {
        $conversationId = $this->resolveConversationId($user, $prompt, $conversationId);

        return new DashboardAssistant($user)
            ->continue($conversationId, $user)
            ->stream($prompt)
            ->withinConversation($conversationId, $user);
    }

    /**
     * Resolve the conversation this turn belongs to, opening one when none is given.
     *
     * The row is created **before** streaming, on purpose: the SDK would create it
     * after the stream —too late to expose the id in a header— and would spend an
     * extra model call generating a title. Titling with the prompt costs nothing.
     *
     * @throws NotFoundError
     * @throws ForbiddenError
     */
    private function resolveConversationId(User $user, string $prompt, ?string $conversationId): string
    {
        if ($conversationId === null) {
            return $this->conversations->storeConversation(
                $user->getMorphClass(),
                $user->getKey(),
                Str::limit($prompt, self::TITLE_LENGTH, preserveWords: true),
            );
        }

        $conversation = Conversation::query()->find($conversationId);

        if ($conversation === null) {
            throw new NotFoundError('La conversación no existe');
        }

        if ($conversation->participant_type !== $user->getMorphClass() || (int) $conversation->participant_id !== $user->getKey()) {
            throw new ForbiddenError('No tienes permisos para acceder a esta conversación');
        }

        return $conversation->getKey();
    }
}
