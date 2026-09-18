<?php

namespace App\Http\Requests\Assistant;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'ChatRequest',
    title: 'Turno del asistente',
    description: <<<'TEXT'
    El cuerpo que manda useChat del Vercel AI SDK con DefaultChatTransport, tal cual, más conversationId. LA API SOLO LEE EL ÚLTIMO MENSAJE: debe ser del usuario (role user) y tener al menos un part de tipo text con texto; los mensajes anteriores del array se ignoran, porque el historial lo guarda y lo reinyecta el servidor desde la conversación. El texto se forma uniendo los parts de tipo text del último mensaje, con un máximo de 4000 caracteres.

    conversationId es opcional: sin él (o null) se abre una conversación nueva para el usuario autenticado y su id viaja en la cabecera X-Conversation-Id de la respuesta; con él se continúa esa conversación, que debe existir (404) y ser del mismo usuario (403).
    TEXT,
    required: ['messages'],
    properties: [
        new OA\Property(property: 'conversationId', description: 'UUID de la conversación a continuar, o null/ausente para abrir una nueva.', type: 'string', format: 'uuid', example: '019968a1-3d7e-7c1a-9b2f-4e5d6c7b8a90', nullable: true),
        new OA\Property(
            property: 'messages',
            description: 'Mensajes UIMessage del Vercel AI SDK. Solo cuenta el último.',
            type: 'array',
            items: new OA\Items(
                required: ['role', 'parts'],
                properties: [
                    new OA\Property(property: 'id', type: 'string', example: 'msg-1'),
                    new OA\Property(property: 'role', type: 'string', enum: ['user', 'assistant', 'system'], example: 'user'),
                    new OA\Property(
                        property: 'parts',
                        type: 'array',
                        items: new OA\Items(
                            required: ['type'],
                            properties: [
                                new OA\Property(property: 'type', type: 'string', example: 'text'),
                                new OA\Property(property: 'text', type: 'string', example: '¿Cuántos viajes hay en ruta ahora?', nullable: true),
                            ],
                            type: 'object',
                        ),
                    ),
                ],
                type: 'object',
            ),
        ),
    ],
    type: 'object',
)]
class ChatRequest extends FormRequest
{
    /** Longest prompt accepted in one turn. */
    private const int MAX_PROMPT_LENGTH = 4000;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'conversationId' => ['sometimes', 'nullable', 'uuid'],
            'messages' => ['required', 'array', 'min:1'],
            'messages.*.role' => ['required', 'string', 'in:user,assistant,system'],
            'messages.*.parts' => ['required', 'array'],
            'messages.*.parts.*.type' => ['required', 'string'],
            'messages.*.parts.*.text' => ['nullable', 'string'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'conversationId.uuid' => 'El identificador de la conversación no es válido',
            'messages.required' => 'Los mensajes son obligatorios',
            'messages.array' => 'Los mensajes deben ser una lista',
            'messages.min' => 'Debes enviar al menos un mensaje',
            'messages.*.role.required' => 'Cada mensaje debe indicar su rol',
            'messages.*.role.in' => 'El rol del mensaje no es válido',
            'messages.*.parts.required' => 'Cada mensaje debe traer sus partes',
            'messages.*.parts.array' => 'Las partes del mensaje deben ser una lista',
            'messages.*.parts.*.type.required' => 'Cada parte del mensaje debe indicar su tipo',
            'messages.*.parts.*.text.string' => 'El texto de la parte debe ser una cadena',
        ];
    }

    /**
     * The last message is the only one that matters: it must come from the user and
     * carry text, or the turn has nothing to answer.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $last = $this->lastMessage();

            if (($last['role'] ?? null) !== 'user') {
                $validator->errors()->add('messages', 'El último mensaje debe ser del usuario');

                return;
            }

            $prompt = $this->prompt();

            if ($prompt === '') {
                $validator->errors()->add('messages', 'El último mensaje debe tener texto');
            } elseif (mb_strlen($prompt) > self::MAX_PROMPT_LENGTH) {
                $validator->errors()->add('messages', 'El mensaje no puede superar los '.self::MAX_PROMPT_LENGTH.' caracteres');
            }
        });
    }

    /**
     * The text of the last message: its `text` parts joined, trimmed.
     */
    public function prompt(): string
    {
        $parts = $this->lastMessage()['parts'] ?? [];

        $texts = array_map(
            static fn (array $part): string => (string) ($part['text'] ?? ''),
            array_filter(
                is_array($parts) ? $parts : [],
                static fn (mixed $part): bool => is_array($part) && ($part['type'] ?? null) === 'text',
            ),
        );

        return trim(implode("\n", $texts));
    }

    /**
     * The conversation to continue, or null to open a new one.
     */
    public function conversationId(): ?string
    {
        $conversationId = $this->input('conversationId');

        return is_string($conversationId) && $conversationId !== '' ? $conversationId : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastMessage(): array
    {
        $messages = $this->input('messages');

        if (! is_array($messages) || $messages === []) {
            return [];
        }

        $last = end($messages);

        return is_array($last) ? $last : [];
    }
}
