<?php

use App\Ai\Agents\DashboardAssistant;
use App\Enums\UserRole;
use App\Models\Carrier;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

if (! function_exists('userWithRole')) {
    /**
     * Create a confirmed user with the given role.
     */
    function userWithRole(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }
}

if (! function_exists('asUser')) {
    /**
     * Authenticate the next request as the given user.
     */
    function asUser(User $user): TestCase
    {
        resetAuthState();

        return test()->withToken(JWTAuth::fromUser($user))->withHeader('Accept', 'application/json');
    }
}

/**
 * The body `useChat` sends by default: the whole thread, of which only the last message counts.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function chatBody(string $text, array $extra = []): array
{
    return array_merge([
        'messages' => [
            ['id' => 'msg-1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => $text]]],
        ],
    ], $extra);
}

/**
 * Send one turn as the given user with the agent faked to answer `$responses`.
 *
 * @param  array<int, mixed>  $responses
 * @param  array<string, mixed>  $extra
 */
function chatAs(User $user, string $text, array $responses = ['Respuesta de prueba'], array $extra = []): TestResponse
{
    DashboardAssistant::fake($responses);

    return asUser($user)->postJson('/api/assistant/chat', chatBody($text, $extra));
}

/**
 * The owner of a company: a `carrier` the middleware lets through.
 */
function assistantCarrierOwner(): User
{
    return User::query()->findOrFail(Carrier::factory()->create()->user_id);
}

/*
|--------------------------------------------------------------------------
| Middleware
|--------------------------------------------------------------------------
*/

it('rechaza con 401 sin token', function () {
    test()->postJson('/api/assistant/chat', chatBody('hola'))
        ->assertUnauthorized()
        ->assertExactJson(['statusCode' => 401, 'message' => 'El token de sesión no es válido o ha expirado', 'data' => null]);
});

it('rechaza con 403 a los pilotos', function () {
    asUser(userWithRole(UserRole::Pilot))->postJson('/api/assistant/chat', chatBody('hola'))
        ->assertForbidden()
        ->assertExactJson(['statusCode' => 403, 'message' => 'No tienes permisos para acceder a este recurso', 'data' => null]);
});

it('rechaza con 403 a un transportista sin empresa', function () {
    asUser(userWithRole(UserRole::Carrier))->postJson('/api/assistant/chat', chatBody('hola'))
        ->assertForbidden()
        ->assertExactJson(['statusCode' => 403, 'message' => 'Debes estar vinculado a un transportista para acceder a este recurso', 'data' => null]);
});

/*
|--------------------------------------------------------------------------
| Validación
|--------------------------------------------------------------------------
*/

it('exige los mensajes', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/assistant/chat', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['messages' => 'Los mensajes son obligatorios']);
});

it('exige que el último mensaje sea del usuario', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/assistant/chat', [
        'messages' => [
            ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'hola']]],
            ['role' => 'assistant', 'parts' => [['type' => 'text', 'text' => 'Hola, ¿en qué te ayudo?']]],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['messages' => 'El último mensaje debe ser del usuario']);
});

it('exige que el último mensaje tenga texto', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/assistant/chat', [
        'messages' => [
            ['role' => 'user', 'parts' => [['type' => 'file', 'url' => 'x'], ['type' => 'text', 'text' => '   ']]],
        ],
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['messages' => 'El último mensaje debe tener texto']);
});

it('rechaza un conversationId que no es UUID', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/assistant/chat', chatBody('hola', ['conversationId' => 'abc']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['conversationId' => 'El identificador de la conversación no es válido']);
});

it('rechaza un mensaje de más de 4000 caracteres', function () {
    asUser(userWithRole(UserRole::Administrator))->postJson('/api/assistant/chat', chatBody(str_repeat('a', 4001)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['messages' => 'El mensaje no puede superar los 4000 caracteres']);
});

/*
|--------------------------------------------------------------------------
| Stream
|--------------------------------------------------------------------------
*/

it('responde con el protocolo de Vercel y abre una conversación nueva', function () {
    $admin = userWithRole(UserRole::Administrator);

    $response = chatAs($admin, '¿Cuántos viajes hay en ruta?', ['Ahora mismo no hay viajes en ruta.']);

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
        ->assertHeader('x-vercel-ai-ui-message-stream', 'v1');

    $conversationId = $response->headers->get('X-Conversation-Id');
    $body = $response->streamedContent();

    expect(Str::isUuid($conversationId))->toBeTrue()
        ->and($body)->toContain('"type":"start"')
        ->and($body)->toContain('"type":"text-delta"')
        ->and($body)->toContain('Ahora')
        ->and($body)->toEndWith("data: [DONE]\n\n");

    $this->assertDatabaseHas('agent_conversations', [
        'id' => $conversationId,
        'participant_type' => $admin->getMorphClass(),
        'participant_id' => $admin->id,
        'title' => '¿Cuántos viajes hay en ruta?',
    ]);

    $messages = DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->orderBy('created_at')->get();

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[0]->content)->toBe('¿Cuántos viajes hay en ruta?')
        ->and($messages[1]->role)->toBe('assistant')
        ->and($messages[1]->content)->toBe('Ahora mismo no hay viajes en ruta.');

    DashboardAssistant::assertPrompted(fn ($prompt) => $prompt->contains('viajes'));
});

it('solo usa el último mensaje del hilo', function () {
    chatAs(userWithRole(UserRole::Administrator), 'ignorado', ['ok'], [
        'messages' => [
            ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'primero']]],
            ['role' => 'assistant', 'parts' => [['type' => 'text', 'text' => 'respuesta']]],
            ['role' => 'user', 'parts' => [['type' => 'text', 'text' => 'segundo'], ['type' => 'text', 'text' => 'y tercero']]],
        ],
    ])->assertOk()->streamedContent();

    DashboardAssistant::assertPrompted("segundo\ny tercero");
    DashboardAssistant::assertNotPrompted('primero');
});

it('continúa una conversación existente y acumula sus mensajes', function () {
    $admin = userWithRole(UserRole::Administrator);

    $first = chatAs($admin, 'primera pregunta', ['primera respuesta']);
    $first->streamedContent();
    $conversationId = $first->headers->get('X-Conversation-Id');

    $second = chatAs($admin, 'segunda pregunta', ['segunda respuesta'], ['conversationId' => $conversationId]);
    $second->assertOk()->streamedContent();

    expect($second->headers->get('X-Conversation-Id'))->toBe($conversationId)
        ->and(DB::table('agent_conversations')->count())->toBe(1)
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $conversationId)->count())->toBe(4);
});

it('responde 404 con un conversationId inexistente', function () {
    chatAs(userWithRole(UserRole::Administrator), 'hola', ['ok'], ['conversationId' => (string) Str::uuid7()])
        ->assertNotFound()
        ->assertExactJson(['statusCode' => 404, 'message' => 'La conversación no existe', 'data' => null]);

    DashboardAssistant::assertNeverPrompted();
});

it('responde 403 con la conversación de otro usuario', function () {
    $first = chatAs(userWithRole(UserRole::Administrator), 'hola', ['ok']);
    $first->streamedContent();
    $conversationId = $first->headers->get('X-Conversation-Id');

    chatAs(userWithRole(UserRole::Manager), 'hola', ['ok'], ['conversationId' => $conversationId])
        ->assertForbidden()
        ->assertExactJson(['statusCode' => 403, 'message' => 'No tienes permisos para acceder a esta conversación', 'data' => null]);

    DashboardAssistant::assertPromptedTimes(1);
});

it('ejecuta las herramientas del tablero y emite su resultado en el stream', function () {
    Trip::factory()->count(2)->create();

    $body = chatAs(userWithRole(UserRole::Manager), '¿Cuántos viajes hay?', [
        new ToolCall('call-1', 'trips_summary', []),
        'Hay 2 viajes registrados.',
    ])->assertOk()->streamedContent();

    expect($body)->toContain('"type":"tool-input-available"')
        ->and($body)->toContain('"toolName":"trips_summary"')
        ->and($body)->toContain('"type":"tool-output-available"')
        ->and($body)->toContain('\"total\":2')
        ->and($body)->toContain('"delta":" registrados."');
});

it('deja pasar a un transportista con empresa', function () {
    chatAs(assistantCarrierOwner(), '¿Cómo va mi flota?', ['Tu flota está completa.'])
        ->assertOk()
        ->assertHeader('x-vercel-ai-ui-message-stream', 'v1');

    DashboardAssistant::assertPromptedTimes(1);
});
