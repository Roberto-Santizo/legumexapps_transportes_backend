<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Assistant\ChatRequest;
use App\Interfaces\Assistant\AssistantServiceInterface;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Assistant',
    description: <<<'TEXT'
    Asistente conversacional del tablero: UN endpoint, POST /api/assistant/chat, que responde en lenguaje natural preguntas sobre viajes, flota y gastos de mantenimiento consultando los mismos agregados de /api/dashboard. El modelo NUNCA toca la base: solo llama a cuatro herramientas de solo lectura (trips_summary, trips_in_route, vehicle_expenses_summary, fleet) que envuelven el servicio del tablero con el usuario autenticado, así que el ámbito por rol es EXACTAMENTE el de /api/dashboard: administrator y manager ven todas las empresas; carrier, solo la suya, y cualquier carrierId que el modelo intente mandar se ignora.

    PERMISOS — mismos middlewares que el tablero: role:administrator,manager,carrier + carrier.required. pilot recibe 403; un carrier sin empresa, 403.

    LA RESPUESTA NO ES EL SOBRE JSON HABITUAL: es un stream Server-Sent Events con el protocolo UI Message Stream del Vercel AI SDK (cabeceras Content-Type: text/event-stream y x-vercel-ai-ui-message-stream: v1, líneas data: {...} terminadas en data: [DONE]), pensado para consumirse con useChat + DefaultChatTransport en React. Los parts posibles son start, start-step, text-start/text-delta/text-end, tool-input-available, tool-output-available, finish-step, finish y error.

    CONVERSACIONES — cada turno se persiste (agent_conversations / agent_conversation_messages) y el servidor reinyecta el historial al modelo: el cliente NO necesita mandar los mensajes anteriores. La cabecera X-Conversation-Id de la respuesta trae el UUID de la conversación (nueva o continuada); el cliente lo guarda y lo manda como conversationId en el siguiente turno. Está expuesta en CORS.

    ERRORES — todo lo que puede fallar con un código (401, 403, 404, 422) falla ANTES de abrir el stream y sale con el sobre JSON de siempre. Una vez abierto el stream, un fallo del proveedor de IA llega DENTRO del stream como un part {"type":"error"} con 200, nunca como 5xx.
    TEXT,
)]
class AssistantController extends Controller
{
    /**
     * Answer one turn of the conversation with the dashboard assistant, streaming.
     *
     * The stream is opened here, not in the service: `toResponse()` is what starts it,
     * and the conversation header has to be set on the response object before that.
     */
    #[OA\Post(
        path: '/api/assistant/chat',
        operationId: 'assistantChat',
        summary: 'Conversar con el asistente del tablero',
        description: <<<'TEXT'
        Envía el último mensaje del usuario y devuelve la respuesta del asistente como stream SSE con el protocolo del Vercel AI SDK. Solo se lee el último mensaje del array: debe ser role user con al menos un part de tipo text no vacío (422 si no). Sin conversationId se abre una conversación nueva; con él se continúa (404 si no existe, 403 si es de otro usuario). El UUID de la conversación viaja siempre en la cabecera X-Conversation-Id.
        TEXT,
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChatRequest')),
        tags: ['Assistant'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Stream SSE con el protocolo UI Message Stream del Vercel AI SDK. Termina con data: [DONE].',
                headers: [
                    new OA\Header(header: 'X-Conversation-Id', description: 'UUID de la conversación, nueva o continuada. Mandarlo como conversationId en el siguiente turno.', schema: new OA\Schema(type: 'string', format: 'uuid')),
                    new OA\Header(header: 'x-vercel-ai-ui-message-stream', description: 'Versión del protocolo: v1.', schema: new OA\Schema(type: 'string', example: 'v1')),
                ],
                content: new OA\MediaType(
                    mediaType: 'text/event-stream',
                    schema: new OA\Schema(type: 'string', example: "data: {\"type\":\"start\",\"messageId\":\"...\"}\n\ndata: {\"type\":\"text-delta\",\"id\":\"...\",\"delta\":\"Hay 3 viajes en ruta.\"}\n\ndata: {\"type\":\"finish\"}\n\ndata: [DONE]\n\n"),
                ),
            ),
            new OA\Response(response: 401, description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'pilot; carrier sin empresa; o conversación de otro usuario («No tienes permisos para acceder a esta conversación»).', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 404, description: 'La conversación no existe', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'Sin mensajes, último mensaje que no es del usuario o sin texto, o conversationId que no es UUID.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function chat(ChatRequest $request, AssistantServiceInterface $assistantService)
    {
        try {
            $stream = $assistantService->chat(auth('api')->user(), $request->prompt(), $request->conversationId());

            $response = $stream->usingVercelDataProtocol()->toResponse($request);
            $response->headers->set('X-Conversation-Id', $stream->conversationId);

            return $response;
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }
}
