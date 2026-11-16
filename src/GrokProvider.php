<?php

/*
 * This file is part of PapiAI,
 * A simple but powerful PHP library for building AI agents.
 *
 * (c) Marcello Duarte <marcello.duarte@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PapiAI\Grok;

use Generator;
use PapiAI\Core\Contracts\NamedToolSelectableInterface;
use PapiAI\Core\Contracts\ProviderInterface;
use PapiAI\Core\Effort;
use PapiAI\Core\Exception\AuthenticationException;
use PapiAI\Core\Exception\ProviderException;
use PapiAI\Core\Exception\RateLimitException;
use PapiAI\Core\Exception\UnknownEffortException;
use PapiAI\Core\Message;
use PapiAI\Core\Response;
use PapiAI\Core\Role;
use PapiAI\Core\StopReason;
use PapiAI\Core\StreamChunk;
use PapiAI\Core\ToolCall;
use PapiAI\Core\ToolChoice;
use RuntimeException;

/**
 * Grok (xAI) API provider for PapiAI.
 *
 * Bridges PapiAI's core types (Message, Response, ToolCall) with xAI's OpenAI-compatible
 * API, handling format conversion in both directions. Supports chat completions, streaming,
 * tool calling, vision (multimodal), and structured JSON output.
 *
 * Authentication is via Bearer token in the Authorization header. All HTTP is done with
 * ext-curl directly, with no HTTP abstraction layer.
 *
 * Supported models:
 *   - grok-3 (latest, most capable)
 *   - grok-3-mini (fast, cost-effective)
 *   - grok-2 (multimodal)
 *
 * @see https://docs.x.ai/docs *
 * The neutral effort option maps to xAI's top-level reasoning_effort. Note Grok 4.5 reasons
 * always and cannot be switched off, so "none" narrows to the shallowest level it accepts.
 */
class GrokProvider implements ProviderInterface, NamedToolSelectableInterface
{
    private const API_URL = 'https://api.x.ai/v1/responses';

    public const MODEL_GROK_4_7 = GrokModel::Grok47->value;
    public const MODEL_GROK_4_6 = GrokModel::Grok46->value;
    public const MODEL_GROK_4_5 = GrokModel::Grok45->value;
    public const MODEL_GROK_4_3 = GrokModel::Grok43->value;

    /** @deprecated Retired 15 May 2026; silently redirects to grok-4.3 and bills at its rates. */
    public const MODEL_GROK_3 = GrokModel::Grok3->value;
    /** @deprecated Retired 15 May 2026; silently redirects to grok-4.3 and bills at its rates. */
    public const MODEL_GROK_3_MINI = GrokModel::Grok3Mini->value;
    /** @deprecated Retired; no longer listed by xAI. */
    public const MODEL_GROK_2 = GrokModel::Grok2->value;

    /**
     * @param string $apiKey       xAI API key for Bearer token authentication
     * @param string $defaultModel Model identifier used when not overridden in options
     * @param int    $defaultMaxTokens Maximum tokens for completions when not overridden
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $defaultModel = self::MODEL_GROK_4_7,
        private readonly int $defaultMaxTokens = 4096,
        private readonly ?Effort $defaultEffort = null,
    ) {
    }

    /**
     * Send a conversation to the Responses API and return the model's turn.
     *
     * @param Message[] $messages Conversation history
     * @param array     $options  Provider options (model, maxTokens, temperature, tools, toolChoice, effort, outputSchema)
     */
    public function chat(array $messages, array $options = []): Response
    {
        $response = $this->request($this->buildPayload($messages, $options));

        return $this->parseResponse($response, $messages);
    }

    /**
     * Stream a response, yielding text deltas and a final completion marker.
     *
     * @param Message[] $messages Conversation history
     * @param array     $options  Provider options
     *
     * @return iterable<StreamChunk>
     */
    public function stream(array $messages, array $options = []): iterable
    {
        $payload = $this->buildPayload($messages, $options);
        $payload['stream'] = true;

        foreach ($this->streamRequest($payload) as $event) {
            $type = $event['type'] ?? '';

            if ($type === 'response.output_text.delta') {
                yield new StreamChunk((string) ($event['delta'] ?? ''));
            }

            if (in_array($type, ['response.completed', 'response.incomplete', 'response.failed'], true)) {
                yield new StreamChunk('', isComplete: true);
            }
        }
    }

    /**
     * Indicates that Grok supports tool/function calling.
     */
    public function supportsTool(): bool
    {
        return true;
    }

    /**
     * Indicates that Grok supports vision (multimodal image input).
     */
    public function supportsVision(): bool
    {
        return true;
    }

    /**
     * Indicates that Grok supports structured JSON output via json_schema response format.
     */
    public function supportsStructuredOutput(): bool
    {
        return true;
    }

    /**
     * Return the provider identifier.
     */
    public function getName(): string
    {
        return 'grok';
    }

    /**
     * Build the Responses API request body.
     *
     * xAI marks Chat Completions legacy, so every request goes to /v1/responses. The history is
     * sent in full each time (`store: false`); papi owns the transcript, the server does not.
     */
    private function buildPayload(array $messages, array $options): array
    {
        $model = (string) ($options['model'] ?? $this->defaultModel);
        [$instructions, $input] = $this->splitConversation($messages);

        $payload = ['model' => $model, 'input' => $input, 'store' => false];

        if ($instructions !== null) {
            $payload['instructions'] = $instructions;
        }

        return $payload + $this->generationSettings($model, $options);
    }

    /**
     * The system message, which Responses takes as `instructions`, and everything else as items.
     *
     * @param Message[] $messages
     *
     * @return array{0: string|null, 1: list<array<string, mixed>>}
     */
    private function splitConversation(array $messages): array
    {
        $instructions = null;
        $input = [];

        foreach ($messages as $message) {
            if (!$message instanceof Message) {
                continue;
            }

            if ($message->isSystem()) {
                $instructions = $message->getText();

                continue;
            }

            foreach ($this->toInputItems($message) as $item) {
                $input[] = $item;
            }
        }

        return [$instructions, $input];
    }

    /**
     * The optional request fields: output ceiling, sampling, schema, tools, forced choice, effort.
     *
     * @return array<string, mixed>
     */
    private function generationSettings(string $model, array $options): array
    {
        $settings = [];

        if (isset($options['maxTokens'])) {
            $settings['max_output_tokens'] = $options['maxTokens'];
        }

        if (isset($options['temperature'])) {
            $settings['temperature'] = $options['temperature'];
        }

        if (isset($options['outputSchema'])) {
            $settings['text'] = ['format' => ['type' => 'json_schema', 'name' => 'response', 'schema' => $options['outputSchema']]];
        }

        if (!empty($options['tools'])) {
            $settings['tools'] = $this->convertTools($options['tools']);
        }

        // Validation lives in core and throws before any HTTP call, tools or no tools.
        if (isset($options['toolChoice'])) {
            $choice = ToolChoice::fromOption($options['toolChoice'], $options['tools'] ?? []);

            if (!empty($options['tools'])) {
                $settings['tool_choice'] = $this->toolChoiceFor($choice);
            }
        }

        $effort = $this->effortFor($options);

        if ($effort !== null) {
            $settings['reasoning'] = ['effort' => $effort->nearestOf($this->levelsFor($model))->value];
        }

        return $settings;
    }

    /**
     * Responses spells a named tool flat, and the modes as bare strings.
     *
     * @return array{type: string, name: string}|string
     */
    private function toolChoiceFor(ToolChoice $choice): array|string
    {
        if ($choice->toolName !== null) {
            return ['type' => 'function', 'name' => $choice->toolName];
        }

        return match ($choice->mode) {
            ToolChoice::NONE => 'none',
            ToolChoice::REQUIRED => 'required',
            default => 'auto',
        };
    }

    /**
     * Translate one PapiAI message into Responses input items.
     *
     * @return list<array<string, mixed>>
     */
    private function toInputItems(Message $message): array
    {
        if ($message->isTool()) {
            return [[
                'type' => 'function_call_output',
                'call_id' => $message->toolCallId,
                'output' => is_string($message->content) ? $message->content : json_encode($message->content),
            ]];
        }

        if ($message->hasToolCalls()) {
            $items = [];

            if ($message->getText() !== '') {
                $items[] = ['role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $message->getText()]]];
            }

            foreach ($message->toolCalls as $toolCall) {
                $items[] = ['type' => 'function_call', 'call_id' => $toolCall->id, 'name' => $toolCall->name, 'arguments' => $toolCall->argumentsAsJson()];
            }

            return $items;
        }

        if (is_array($message->content)) {
            return [['role' => $this->convertRole($message->role), 'content' => $this->convertMultimodalContent($message->content)]];
        }

        if ($message->role === Role::Assistant) {
            return [['role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $message->content]]]];
        }

        return [['role' => $this->convertRole($message->role), 'content' => $message->content]];
    }

    /**
     * Read the model's turn out of a Responses body: text from message items, calls from
     * function_call items; reasoning items are skipped.
     *
     * @param array<string, mixed> $response
     * @param Message[]            $messages
     */
    private function parseResponse(array $response, array $messages): Response
    {
        $text = '';
        $toolCalls = [];

        foreach ($response['output'] ?? [] as $item) {
            $type = $item['type'] ?? '';

            if ($type === 'message') {
                foreach ($item['content'] ?? [] as $part) {
                    if (($part['type'] ?? '') === 'output_text') {
                        $text .= $part['text'] ?? '';
                    }
                }
            }

            if ($type === 'function_call') {
                $toolCalls[] = new ToolCall(
                    id: (string) ($item['call_id'] ?? $item['id'] ?? ''),
                    name: (string) ($item['name'] ?? ''),
                    arguments: json_decode((string) ($item['arguments'] ?? '{}'), true) ?? [],
                );
            }
        }

        $incomplete = ($response['status'] ?? 'completed') === 'incomplete';

        return new Response(
            text: $text,
            toolCalls: $toolCalls,
            messages: $messages,
            usage: $response['usage'] ?? [],
            stopReason: $incomplete ? (string) ($response['incomplete_details']['reason'] ?? 'incomplete') : ($toolCalls !== [] ? 'tool_calls' : 'stop'),
            stoppedBecause: $this->stoppedBecauseOf($response, $toolCalls !== []),
        );
    }

    /**
     * The URL chat and streaming post to. Protected so a test double can observe it.
     */
    protected function chatUrl(): string
    {
        return self::API_URL;
    }

    /**
     * The effort this request asks for: the per-call option, else the provider default.
     *
     * @param array<string, mixed> $options The caller's request options
     *
     * @throws UnknownEffortException When the level is not one core defines
     */
    private function effortFor(array $options): ?Effort
    {
        if (!isset($options['effort'])) {
            return $this->defaultEffort;
        }

        $level = (string) $options['effort'];

        return Effort::tryFrom($level) ?? throw new UnknownEffortException($level);
    }

    /**
     * The reasoning-effort levels a model accepts. The model answers when we know it; an unknown
     * ID is assumed to take the full set, which every model since 4.6 does.
     *
     * @return non-empty-list<Effort>
     */
    private function levelsFor(string $model): array
    {
        $known = GrokModel::tryFrom($model)?->effortLevels() ?? [];

        return $known !== [] ? $known : [Effort::None, Effort::Low, Effort::Medium, Effort::High];
    }

    /**
     * Convert multimodal parts to Responses input parts. A base64 image travels as a data URL.
     *
     * @param array<array{type: string, text?: string, source?: array}> $content
     *
     * @return list<array<string, string>>
     */
    private function convertMultimodalContent(array $content): array
    {
        $parts = [];

        foreach ($content as $part) {
            if ($part['type'] === 'text') {
                $parts[] = ['type' => 'input_text', 'text' => $part['text']];

                continue;
            }

            if ($part['type'] !== 'image') {
                continue;
            }

            $source = $part['source'];
            $parts[] = [
                'type' => 'input_image',
                'image_url' => $source['type'] === 'url' ? $source['url'] : sprintf('data:%s;base64,%s', $source['media_type'], $source['data']),
            ];
        }

        return $parts;
    }

    /**
     * Convert PapiAI tool definitions to the flat Responses tool shape.
     *
     * @param array<array{name: string, description: string, input_schema?: array, parameters?: array}> $tools
     *
     * @return list<array{type: string, name: string, description: string, parameters: array}>
     */
    private function convertTools(array $tools): array
    {
        $declared = [];

        foreach ($tools as $tool) {
            if (is_array($tool)) {
                $declared[] = [
                    'type' => 'function',
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['input_schema'] ?? $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
                ];
            }
        }

        return $declared;
    }

    /**
     * Convert Role to OpenAI-compatible role string.
     */
    private function convertRole(Role $role): string
    {
        return match ($role) {
            Role::System => 'system',
            Role::User => 'user',
            Role::Assistant => 'assistant',
            Role::Tool => 'tool',
        };
    }

    /**
     * Send a synchronous POST request to the xAI API and return the decoded response.
     *
     * @param array $payload The JSON-encodable request body
     *
     * @return array The decoded JSON response
     *
     * @throws AuthenticationException When the API key is invalid (HTTP 401)
     * @throws RateLimitException      When the rate limit is exceeded (HTTP 429)
     * @throws ProviderException       On any other API error (HTTP 4xx/5xx)
     * @throws RuntimeException        On cURL transport failure
     */
    protected function request(array $payload): array
    {
        $ch = curl_init($this->chatUrl());

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error !== '') {
            throw new RuntimeException("Grok API request failed: {$error}");
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            $this->throwForStatusCode($httpCode, $data);
        }

        return $data;
    }

    /**
     * Map an HTTP error status code to the appropriate PapiAI exception.
     *
     * @param int        $httpCode HTTP status code (>= 400)
     * @param array|null $data     Decoded JSON error response body, if available
     *
     * @throws AuthenticationException When the API key is invalid (HTTP 401)
     * @throws RateLimitException      When the rate limit is exceeded (HTTP 429)
     * @throws ProviderException       On any other API error
     */
    protected function throwForStatusCode(int $httpCode, ?array $data): never
    {
        $errorMessage = $data['error']['message'] ?? 'Unknown error';

        if ($httpCode === 401) {
            throw new AuthenticationException(
                $this->getName(),
                $httpCode,
                $data,
            );
        }

        if ($httpCode === 429) {
            throw new RateLimitException(
                $this->getName(),
                statusCode: $httpCode,
                responseBody: $data,
            );
        }

        throw new ProviderException(
            "Grok API error ({$httpCode}): {$errorMessage}",
            $this->getName(),
            $httpCode,
            $data,
        );
    }

    /**
     * Send a streaming POST request to the xAI API and yield parsed SSE events.
     *
     * Buffers the full response then parses SSE `data:` lines, yielding each decoded
     * JSON event until the `[DONE]` sentinel is encountered.
     *
     * @param array $payload The JSON-encodable request body (must include stream: true)
     *
     * @return Generator<int, array> Yields decoded JSON event arrays from the SSE stream
     */
    protected function streamRequest(array $payload): Generator
    {
        $ch = curl_init($this->chatUrl());

        $buffer = '';
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer) {
                $buffer .= $data;

                return strlen($data);
            },
        ]);

        curl_exec($ch);

        // Parse SSE events
        $lines = explode("\n", $buffer);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data: ')) {
                $json = substr($line, 6);
                if ($json === '[DONE]') {
                    break;
                }
                $event = json_decode($json, true);
                if ($event !== null) {
                    yield $event;
                }
            }
        }
    }

    /**
     * Why the model stopped, in core's vocabulary.
     *
     * @param array<string, mixed> $response
     */
    private function stoppedBecauseOf(array $response, bool $calledTools): StopReason
    {
        if ($calledTools) {
            return StopReason::ToolCalls;
        }

        return match ($response['status'] ?? 'completed') {
            'completed' => StopReason::Stop,
            'incomplete' => match ($response['incomplete_details']['reason'] ?? null) {
                'max_output_tokens' => StopReason::MaxTokens,
                'content_filter' => StopReason::Refused,
                default => StopReason::Other,
            },
            default => StopReason::Other,
        };
    }
}
