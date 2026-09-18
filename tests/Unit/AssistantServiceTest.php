<?php

use App\Ai\Agents\DashboardAssistant;
use App\Enums\UserRole;
use App\Errors\ForbiddenError;
use App\Errors\NotFoundError;
use App\Interfaces\Assistant\AssistantServiceInterface;
use App\Models\User;
use App\Services\Assistant\AssistantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Responses\StreamableAgentResponse;

/**
 * Resolve the service through the container, which checks the Provider binding too.
 */
function assistantService(): AssistantServiceInterface
{
    return app(AssistantServiceInterface::class);
}

function assistantUser(): User
{
    return User::factory()->create(['role' => UserRole::Administrator]);
}

/**
 * A conversation already stored for the given user, as a previous turn would leave it.
 */
function conversationOf(User $user): string
{
    return app(ConversationStore::class)->storeConversation($user->getMorphClass(), $user->id, 'Anterior');
}

it('resuelve el contrato del dominio contra su implementación', function () {
    expect(assistantService())->toBeInstanceOf(AssistantService::class);
});

it('abre una conversación titulada con el prompt antes de streamear', function () {
    DashboardAssistant::fake(['ok']);
    $user = assistantUser();

    $response = assistantService()->chat($user, '¿Cuántos viajes hay en ruta?', null);

    expect($response)->toBeInstanceOf(StreamableAgentResponse::class)
        ->and(Str::isUuid($response->conversationId))->toBeTrue()
        ->and($response->conversationUser?->is($user))->toBeTrue();

    $this->assertDatabaseHas('agent_conversations', [
        'id' => $response->conversationId,
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->id,
        'title' => '¿Cuántos viajes hay en ruta?',
    ]);

    expect(DB::table('agent_conversation_messages')->count())->toBe(0);
});

it('recorta el título a cien caracteres sin partir palabras', function () {
    DashboardAssistant::fake(['ok']);
    $prompt = str_repeat('palabra ', 20);

    $response = assistantService()->chat(assistantUser(), $prompt, null);

    $title = DB::table('agent_conversations')->where('id', $response->conversationId)->value('title');

    expect(mb_strlen($title))->toBeLessThanOrEqual(100)
        ->and($title)->toEndWith('palabra...');
});

it('continúa la conversación del usuario sin crear otra', function () {
    DashboardAssistant::fake(['ok']);
    $user = assistantUser();
    $conversationId = conversationOf($user);

    $response = assistantService()->chat($user, 'otra pregunta', $conversationId);

    expect($response->conversationId)->toBe($conversationId)
        ->and(DB::table('agent_conversations')->count())->toBe(1);
});

it('persiste el par de mensajes al consumir el stream', function () {
    DashboardAssistant::fake(['respuesta']);
    $user = assistantUser();

    $response = assistantService()->chat($user, 'pregunta', null);
    iterator_to_array($response);

    $roles = DB::table('agent_conversation_messages')
        ->where('conversation_id', $response->conversationId)
        ->orderBy('created_at')
        ->pluck('role')
        ->all();

    expect($roles)->toBe(['user', 'assistant']);
});

it('lanza 404 con una conversación inexistente', function () {
    DashboardAssistant::fake(['ok']);

    assistantService()->chat(assistantUser(), 'hola', (string) Str::uuid7());
})->throws(NotFoundError::class, 'La conversación no existe');

it('lanza 403 con la conversación de otro usuario', function () {
    DashboardAssistant::fake(['ok']);
    $conversationId = conversationOf(assistantUser());

    assistantService()->chat(assistantUser(), 'hola', $conversationId);
})->throws(ForbiddenError::class, 'No tienes permisos para acceder a esta conversación');
