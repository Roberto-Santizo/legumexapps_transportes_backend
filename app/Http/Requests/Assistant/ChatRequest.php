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
    La conversación completa tal como la tiene el cliente: una lista de mensajes en orden cronológico, cada uno con su role (user o assistant) y su content. La memoria vive en el cliente: la API no guarda nada y en cada petición recibe el historial entero. EL ÚLTIMO MENSAJE ES LA PREGUNTA DEL TURNO: debe ser role user, con texto (se recorta con trim) y de 1 a 4000 caracteres; los anteriores se pasan al modelo como contexto, sin filtrar. Máximo 50 mensajes por petición y 10000 caracteres por mensaje de historial.
    TEXT,
    required: ['messages'],
    properties: [
        new OA\Property(
            property: 'messages',
            description: 'Historial en orden cronológico, terminando en el mensaje del usuario a responder. Entre 1 y 50 mensajes.',
            type: 'array',
            minItems: 1,
            maxItems: 50,
            items: new OA\Items(
                required: ['role', 'content'],
                properties: [
                    new OA\Property(property: 'role', type: 'string', enum: ['user', 'assistant'], example: 'user'),
                    new OA\Property(property: 'content', type: 'string', maxLength: 10000, example: '¿Cuántos viajes hay en ruta ahora?'),
                ],
                type: 'object',
            ),
        ),
    ],
    type: 'object',
)]
class ChatRequest extends FormRequest
{
    /** Longest prompt accepted in one turn: the last message, the one the model answers. */
    private const int MAX_PROMPT_LENGTH = 4000;

    /** Longest content accepted for a message of the history, the assistant's included. */
    private const int MAX_HISTORY_MESSAGE_LENGTH = 10000;

    /** Most messages —history plus prompt— accepted in one request. */
    private const int MAX_MESSAGES = 50;

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
            'messages' => ['required', 'array', 'min:1', 'max:'.self::MAX_MESSAGES],
            'messages.*.role' => ['required', 'string', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:'.self::MAX_HISTORY_MESSAGE_LENGTH],
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
            'messages.required' => 'Los mensajes son obligatorios',
            'messages.array' => 'Los mensajes deben ser una lista',
            'messages.min' => 'Debes enviar al menos un mensaje',
            'messages.max' => 'No puedes enviar más de '.self::MAX_MESSAGES.' mensajes',
            'messages.*.role.required' => 'Cada mensaje debe indicar su rol',
            'messages.*.role.in' => 'El rol del mensaje debe ser user o assistant',
            'messages.*.content.required' => 'Cada mensaje debe tener contenido',
            'messages.*.content.string' => 'El contenido del mensaje debe ser una cadena de texto',
            'messages.*.content.max' => 'Un mensaje no puede superar los '.self::MAX_HISTORY_MESSAGE_LENGTH.' caracteres',
        ];
    }

    /**
     * The last message is the question of the turn: it must come from the user and
     * fit the prompt limit, which is tighter than the one of the history.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->lastMessage()['role'] !== 'user') {
                $validator->errors()->add('messages', 'El último mensaje debe ser del usuario');

                return;
            }

            if (mb_strlen($this->prompt()) > self::MAX_PROMPT_LENGTH) {
                $validator->errors()->add('messages', 'El mensaje no puede superar los '.self::MAX_PROMPT_LENGTH.' caracteres');
            }
        });
    }

    /**
     * The text of the turn: the last message, trimmed.
     */
    public function prompt(): string
    {
        return trim($this->lastMessage()['content']);
    }

    /**
     * The previous turns, in order: every message but the last one.
     *
     * @return list<array{role: string, content: string}>
     */
    public function history(): array
    {
        return array_slice($this->messagesInput(), 0, -1);
    }

    /**
     * @return array{role: string, content: string}
     */
    private function lastMessage(): array
    {
        $messages = $this->messagesInput();

        return $messages[array_key_last($messages)];
    }

    /**
     * The messages as sent, reduced to the two keys the rules guarantee.
     *
     * Read from the input and not from `validated()`, because the after hook runs
     * while the validator is still deciding and `validated()` would re-run it.
     *
     * @return list<array{role: string, content: string}>
     */
    private function messagesInput(): array
    {
        return array_values(array_map(
            static fn (array $message): array => ['role' => (string) $message['role'], 'content' => (string) $message['content']],
            $this->input('messages'),
        ));
    }
}
