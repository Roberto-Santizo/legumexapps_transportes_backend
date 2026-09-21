<?php

namespace App\Http\Controllers;

use App\Helpers\ResponseHandler;
use App\Http\Requests\Assistant\ChatRequest;
use App\Interfaces\Assistant\AssistantServiceInterface;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Assistant',
    description: <<<'TEXT'
    Asistente conversacional del tablero: UN endpoint, POST /api/assistant/chat, que responde en lenguaje natural preguntas sobre viajes, flota y gastos de mantenimiento consultando los mismos datos de /api/dashboard, /api/trips, /api/vehicles y /api/vehicle-expenses. El modelo NUNCA toca la base: llama a trece herramientas que envuelven los services con el usuario autenticado —once de consulta (trips_summary, trips_in_route, vehicle_expenses_summary, fleet, trips, trip, trip_fuels, trip_expenses, trip_timeouts, vehicle, vehicle_expenses) y dos que generan reportes (export_trips, export_vehicle_expenses)—, así que el ámbito por rol es EXACTAMENTE el de cada endpoint: administrator y manager ven todas las empresas; carrier, solo la suya, y cualquier carrierId que el modelo intente mandar se ignora.

    REPORTES EXCEL — cuando el usuario pide explícitamente un archivo («genérame un reporte de Excel de los viajes terminados»), el modelo llama a export_trips o export_vehicle_expenses con los mismos filtros de trips / vehicle_expenses. La API genera el .xlsx en el servidor, lo sube al bucket bajo reports/{uuid}.xlsx con URL pública permanente (mismo mecanismo que las facturas y los documentos del piloto) y la herramienta devuelve {fileName, url, rows, total, truncated} (+ totalAmount en gastos). Esa salida viaja en el part tool-output-available (output es un string JSON: hay que parsearlo) y el modelo la cita como enlace Markdown en el texto. Tope de 5000 filas por archivo: si el filtro supera el tope, truncated es true y el archivo trae solo las primeras. El nombre real del objeto es el uuid; fileName es solo para mostrar. No hay purga de reportes ni endpoint de descarga propio.

    PERMISOS — mismos middlewares que el tablero: role:administrator,manager,carrier + carrier.required. pilot recibe 403; un carrier sin empresa, 403.

    LA RESPUESTA NO ES EL SOBRE JSON HABITUAL: es un stream Server-Sent Events con el protocolo UI Message Stream del Vercel AI SDK (cabeceras Content-Type: text/event-stream y x-vercel-ai-ui-message-stream: v1, líneas data: {...} terminadas en data: [DONE]), pensado para consumirse con useChat + DefaultChatTransport en React. Los parts posibles son start, start-step, text-start/text-delta/text-end, tool-input-available, tool-output-available, finish-step, finish y error.

    MEMORIA EN EL CLIENTE — la API no guarda la conversación: en cada petición el cliente manda la lista completa de mensajes (role user/assistant + content) en orden cronológico, el último es la pregunta del turno y los anteriores se pasan al modelo como contexto. Nada se persiste; si el cliente pierde la lista, el asistente no la recuerda.

    ERRORES — todo lo que puede fallar con un código (401, 403, 422) falla ANTES de abrir el stream y sale con el sobre JSON de siempre. Una vez abierto el stream, un fallo del proveedor de IA llega DENTRO del stream como un part {"type":"error"} con 200, nunca como 5xx.
    TEXT,
)]
class AssistantController extends Controller
{
    /**
     * Answer one turn with the dashboard assistant, streaming, with the history the client sent.
     *
     * The stream is opened here, not in the service: `toResponse()` is what starts it.
     */
    #[OA\Post(
        path: '/api/assistant/chat',
        operationId: 'assistantChat',
        summary: 'Conversar con el asistente del tablero',
        description: <<<'TEXT'
        Envía la conversación completa y devuelve la respuesta del asistente como stream SSE con el protocolo del Vercel AI SDK. El cuerpo lleva un único campo, messages: entre 1 y 50 mensajes con role (user o assistant) y content, en orden cronológico; el último debe ser del usuario y tener de 1 a 4000 caracteres (422 si no), y los anteriores viajan al modelo como contexto. Nada se persiste en el servidor.
        TEXT,
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChatRequest')),
        tags: ['Assistant'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Stream SSE con el protocolo UI Message Stream del Vercel AI SDK. Termina con data: [DONE].',
                headers: [
                    new OA\Header(header: 'x-vercel-ai-ui-message-stream', description: 'Versión del protocolo: v1.', schema: new OA\Schema(type: 'string', example: 'v1')),
                ],
                content: new OA\MediaType(
                    mediaType: 'text/event-stream',
                    schema: new OA\Schema(type: 'string', example: "data: {\"type\":\"start\",\"messageId\":\"...\"}\n\ndata: {\"type\":\"text-delta\",\"id\":\"...\",\"delta\":\"Hay 3 viajes en ruta.\"}\n\ndata: {\"type\":\"finish\"}\n\ndata: [DONE]\n\n"),
                ),
            ),
            new OA\Response(response: 401, description: 'Token ausente, manipulado o expirado. El mensaje devuelto es: El token de sesión no es válido o ha expirado', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 403, description: 'pilot, o carrier sin empresa.', content: new OA\JsonContent(ref: '#/components/schemas/ApiError')),
            new OA\Response(response: 422, description: 'Sin mensajes o más de 50; rol que no es user ni assistant; contenido ausente, no textual o de más de 10000 caracteres; último mensaje que no es del usuario o de más de 4000 caracteres.', content: new OA\JsonContent(ref: '#/components/schemas/ValidationError')),
        ],
    )]
    public function chat(ChatRequest $request, AssistantServiceInterface $assistantService)
    {
        try {
            $stream = $assistantService->chat(auth('api')->user(), $request->prompt(), $request->history());

            return $stream->usingVercelDataProtocol()->toResponse($request);
        } catch (\Throwable $th) {
            return ResponseHandler::error($th);
        }
    }
}
