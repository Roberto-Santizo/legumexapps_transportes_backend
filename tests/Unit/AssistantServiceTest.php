<?php

use App\Ai\Agents\DashboardAssistant;
use App\Enums\UserRole;
use App\Interfaces\Assistant\AssistantServiceInterface;
use App\Models\User;
use App\Services\Assistant\AssistantService;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
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

it('resuelve el contrato del dominio contra su implementación', function () {
    expect(assistantService())->toBeInstanceOf(AssistantService::class);
});

it('devuelve el stream del agente preguntando solo con el prompt', function () {
    DashboardAssistant::fake(['ok']);

    $response = assistantService()->chat(assistantUser(), '¿y de esos cuántos son míos?', [
        ['role' => 'user', 'content' => '¿Cuántos viajes hay en ruta?'],
        ['role' => 'assistant', 'content' => 'Hay 3.'],
    ]);

    expect($response)->toBeInstanceOf(StreamableAgentResponse::class)
        ->and($response->conversationId)->toBeNull();

    DashboardAssistant::assertPromptedTimes(1);
    DashboardAssistant::assertPrompted('¿y de esos cuántos son míos?');
    DashboardAssistant::assertNotPrompted('¿Cuántos viajes hay en ruta?');
});

it('no persiste nada al consumir el stream', function () {
    DashboardAssistant::fake(['respuesta']);

    $response = assistantService()->chat(assistantUser(), 'pregunta');
    iterator_to_array($response);

    expect($response->text)->toBe('respuesta')
        ->and(DB::table('agent_conversations')->count())->toBe(0)
        ->and(DB::table('agent_conversation_messages')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| El agente traduce el historial del cliente a mensajes del SDK
|--------------------------------------------------------------------------
*/

it('convierte el historial en mensajes del SDK en el mismo orden', function () {
    $messages = new DashboardAssistant(assistantUser(), [
        ['role' => 'user', 'content' => 'primera'],
        ['role' => 'assistant', 'content' => 'respuesta'],
        ['role' => 'user', 'content' => 'segunda'],
    ])->messages();

    expect($messages)->toHaveCount(3)
        ->and($messages[0])->toBeInstanceOf(UserMessage::class)
        ->and($messages[0]->content)->toBe('primera')
        ->and($messages[1])->toBeInstanceOf(AssistantMessage::class)
        ->and($messages[1]->content)->toBe('respuesta')
        ->and($messages[2])->toBeInstanceOf(UserMessage::class)
        ->and($messages[2]->content)->toBe('segunda');
});

it('sin historial no aporta mensajes previos', function () {
    expect(new DashboardAssistant(assistantUser())->messages())->toBe([]);
});
