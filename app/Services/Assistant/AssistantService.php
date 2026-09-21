<?php

namespace App\Services\Assistant;

use App\Ai\Agents\DashboardAssistant;
use App\Interfaces\Assistant\AssistantServiceInterface;
use App\Models\User;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Override;

class AssistantService implements AssistantServiceInterface
{
    #[Override]
    public function chat(User $user, string $prompt, array $history = []): StreamableAgentResponse
    {
        return new DashboardAssistant($user, $history)->stream($prompt);
    }
}
