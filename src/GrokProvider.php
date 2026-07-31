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
use PapiAI\Core\Contracts\ProviderInterface;
use PapiAI\Core\Exception\AuthenticationException;
use PapiAI\Core\Exception\ProviderException;
use PapiAI\Core\Exception\RateLimitException;
use PapiAI\Core\Message;
use PapiAI\Core\Response;
use PapiAI\Core\Role;
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
 * The neutral `effort` option is accepted and ignored here. xAI does expose a top-level reasoning_effort parameter, but papi does not map it yet, so the option is accepted and ignored for now. Note Grok 4.5 cannot disable reasoning at all. Ignoring it
 * degrades nothing the caller was promised, which is why it is silent where an unhonourable
 * `toolChoice` throws.
 */
class GrokProvider implements ProviderInterface
{
    private const API_URL = 'https://api.x.ai/v1/chat/completions';

    public const MODEL_GROK_4_5 = 'grok-4.5';
    public const MODEL_GROK_4_3 = 'grok-4.3';

    /** @deprecated Retired 15 May 2026; silently redirects to grok-4.3 and bills at its rates. */
    public const MODEL_GROK_3 = 'grok-3';
    /** @deprecated Retired 15 May 2026; silently redirects to grok-4.3 and bills at its rates. */
    public const MODEL_GROK_3_MINI = 'grok-3-mini';
    /** @deprecated Retired; no longer listed by xAI. */
    public const MODEL_GROK_2 = 'grok-2';

    /**
     * @param string $apiKey       xAI API key for Bearer token authentication
     * @param string $defaultModel Model identifier used when not overridden in options
     * @param int    $defaultMaxTokens Maximum tokens for completions when not overridden
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $defaultModel = self::MODEL_GROK_4_5,
        private readonly int $defaultMaxTokens = 4096,
    ) {
    }

    /**
     * Send a chat completion request to the xAI API.
     *
     * @param Message[] $messages Conversation messages in PapiAI format
     * @param array     $options  Provider options (model, maxTokens, temperature, stopSequences, outputSchema, tools)
     *
     * @return Response The parsed API response with message content and metadata
     *
     * @throws AuthenticationException When the API key is invalid (HTTP 401)
     * @throws RateLimitException      When the rate limit is exceeded (HTTP 429)
     * @throws ProviderException       On any other API error
     * @throws RuntimeException        On cURL transport failure
     */
    public function chat(array $messages, array $options = []): Response
    {
        $payload = $this->buildPayload($messages, $options);
        $response = $this->request($payload);

        return Response::fromOpenAI($response, $messages);
    }

    /**
     * Stream a chat completion response from the xAI API as server-sent events.
     *
     * @param Message[] $messages Conversation messages in PapiAI format
     * @param array     $options  Provider options (model, maxTokens, temperature, stopSequences, outputSchema, tools)
     *
     * @return iterable<StreamChunk> Yields StreamChunk objects as content arrives
     *
     * @throws RuntimeException On cURL transport failure
     */
    public function stream(array $messages, array $options = []): iterable
    {
        $payload = $this->buildPayload($messages, $options);
        $payload['stream'] = true;

        foreach ($this->streamRequest($payload) as $event) {
            $delta = $event['choices'][0]['delta'] ?? [];
            if (isset($delta['content'])) {
                yield new StreamChunk($delta['content']);
            }
            if (($event['choices'][0]['finish_reason'] ?? null) !== null) {
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
     * Build the API request payload.
     */
    private function buildPayload(array $messages, array $options): array
    {
        $apiMessages = [];

        foreach ($messages as $message) {
            if ($message instanceof Message) {
                $apiMessages[] = $this->convertMessage($message);
            }
        }

        $payload = [
            'model' => $options['model'] ?? $this->defaultModel,
            'messages' => $apiMessages,
        ];

        if (isset($options['maxTokens'])) {
            $payload['max_tokens'] = $options['maxTokens'];
        }

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        if (isset($options['stopSequences'])) {
            $payload['stop'] = $options['stopSequences'];
        }

        // Handle structured output / JSON mode
        if (isset($options['outputSchema'])) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'response',
                    'schema' => $options['outputSchema'],
                ],
            ];
        }

        // Handle tools
        if (isset($options['tools']) && !empty($options['tools'])) {
            $payload['tools'] = $this->convertTools($options['tools']);
        }

        // Forced tool choice (OpenAI-compatible). Validation lives in core and throws before any HTTP call.
        if (isset($options['toolChoice'])) {
            $choice = ToolChoice::fromOption($options['toolChoice'], $options['tools'] ?? []);

            if (!empty($options['tools'])) {
                $payload['tool_choice'] = $choice->toolName !== null
                    ? ['type' => 'function', 'function' => ['name' => $choice->toolName]]
                    : match ($choice->mode) {
                        ToolChoice::NONE => 'none',
                        ToolChoice::REQUIRED => 'required',
                        default => 'auto',
                    };
            }
        }

        return $payload;
    }

    /**
     * Convert a Message to OpenAI-compatible API format.
     */
    private function convertMessage(Message $message): array
    {
        $apiMessage = [
            'role' => $this->convertRole($message->role),
        ];

        if ($message->isTool()) {
            $apiMessage['role'] = 'tool';
            $apiMessage['content'] = $message->content;
            $apiMessage['tool_call_id'] = $message->toolCallId;
        } elseif ($message->hasToolCalls()) {
            $apiMessage['content'] = $message->getText() ?: null;
            $apiMessage['tool_calls'] = array_map(function (ToolCall $tc) {
                return [
                    'id' => $tc->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $tc->name,
                        'arguments' => json_encode($tc->arguments),
                    ],
                ];
            }, $message->toolCalls);
        } elseif (is_array($message->content)) {
            $apiMessage['content'] = $this->convertMultimodalContent($message->content);
        } else {
            $apiMessage['content'] = $message->content;
        }

        return $apiMessage;
    }

    /**
     * Convert multimodal content to OpenAI-compatible format.
     */
    private function convertMultimodalContent(array $content): array
    {
        $parts = [];

        foreach ($content as $part) {
            if ($part['type'] === 'text') {
                $parts[] = ['type' => 'text', 'text' => $part['text']];
            } elseif ($part['type'] === 'image') {
                $source = $part['source'];
                if ($source['type'] === 'url') {
                    $parts[] = [
                        'type' => 'image_url',
                        'image_url' => ['url' => $source['url']],
                    ];
                } else {
                    $parts[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$source['media_type']};base64,{$source['data']}",
                        ],
                    ];
                }
            }
        }

        return $parts;
    }

    /**
     * Convert tools from PapiAI format to OpenAI-compatible format.
     */
    private function convertTools(array $tools): array
    {
        $openaiTools = [];

        foreach ($tools as $tool) {
            if (is_array($tool)) {
                $openaiTools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                        'parameters' => $tool['input_schema'] ?? $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
                    ],
                ];
            }
        }

        return $openaiTools;
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
        $ch = curl_init(self::API_URL);

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

        curl_close($ch);

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
        $ch = curl_init(self::API_URL);

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
        curl_close($ch);

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
}
