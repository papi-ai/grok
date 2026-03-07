<?php

declare(strict_types=1);

use PapiAI\Core\Message;
use PapiAI\Core\Response;
use PapiAI\Core\StreamChunk;
use PapiAI\Core\ToolCall;
use PapiAI\Grok\GrokProvider;

class TestableGrokProvider extends GrokProvider
{
    public array $lastPayload = [];
    public array $mockResponse = [];
    public string $mockStreamData = '';

    protected function request(array $payload): array
    {
        $this->lastPayload = $payload;

        return $this->mockResponse;
    }

    protected function streamRequest(array $payload): Generator
    {
        $this->lastPayload = $payload;

        $lines = explode("\n", $this->mockStreamData);
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

describe('GrokProvider', function () {
    describe('construction and capabilities', function () {
        it('can be instantiated with an API key', function () {
            $provider = new TestableGrokProvider('test-key');

            expect($provider)->toBeInstanceOf(GrokProvider::class);
        });

        it('returns "grok" as provider name', function () {
            $provider = new TestableGrokProvider('test-key');

            expect($provider->getName())->toBe('grok');
        });

        it('supports tools', function () {
            $provider = new TestableGrokProvider('test-key');

            expect($provider->supportsTool())->toBeTrue();
        });

        it('supports vision', function () {
            $provider = new TestableGrokProvider('test-key');

            expect($provider->supportsVision())->toBeTrue();
        });

        it('supports structured output', function () {
            $provider = new TestableGrokProvider('test-key');

            expect($provider->supportsStructuredOutput())->toBeTrue();
        });
    });

    describe('chat', function () {
        it('sends messages and returns a Response', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockResponse = [
                'choices' => [
                    [
                        'message' => ['content' => 'Hello!', 'role' => 'assistant'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ];

            $messages = [Message::user('Hi')];
            $response = $provider->chat($messages);

            expect($response)->toBeInstanceOf(Response::class);
            expect($response->text)->toBe('Hello!');
        });

        it('sends user messages in OpenAI format', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockResponse = [
                'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
            ];

            $provider->chat([Message::user('Hello')]);

            expect($provider->lastPayload['messages'])->toBe([
                ['role' => 'user', 'content' => 'Hello'],
            ]);
        });

        it('handles system messages', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockResponse = [
                'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
            ];

            $provider->chat([
                Message::system('You are helpful'),
                Message::user('Hi'),
            ]);

            expect($provider->lastPayload['messages'][0])->toBe([
                'role' => 'system',
                'content' => 'You are helpful',
            ]);
            expect($provider->lastPayload['messages'][1])->toBe([
                'role' => 'user',
                'content' => 'Hi',
            ]);
        });

        it('uses the default model', function () {
            $provider = new TestableGrokProvider('test-key', 'grok-3');
            $provider->mockResponse = [
                'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
            ];

            $provider->chat([Message::user('Hi')]);

            expect($provider->lastPayload['model'])->toBe('grok-3');
        });

        it('allows model override via options', function () {
            $provider = new TestableGrokProvider('test-key', 'grok-3');
            $provider->mockResponse = [
                'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
            ];

            $provider->chat([Message::user('Hi')], ['model' => 'grok-3-mini']);

            expect($provider->lastPayload['model'])->toBe('grok-3-mini');
        });

        it('passes maxTokens option', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockResponse = [
                'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
            ];

            $provider->chat([Message::user('Hi')], ['maxTokens' => 1000]);

            expect($provider->lastPayload['max_tokens'])->toBe(1000);
        });

        it('passes temperature option', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockResponse = [
                'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
            ];

            $provider->chat([Message::user('Hi')], ['temperature' => 0.5]);

            expect($provider->lastPayload['temperature'])->toBe(0.5);
        });

        it('passes stopSequences option', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockResponse = [
                'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
            ];

            $provider->chat([Message::user('Hi')], ['stopSequences' => ['STOP']]);

            expect($provider->lastPayload['stop'])->toBe(['STOP']);
        });

        it('converts tools to OpenAI format', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockResponse = [
                'choices' => [['message' => ['content' => 'OK'], 'finish_reason' => 'stop']],
            ];

            $tools = [
                [
                    'name' => 'get_weather',
                    'description' => 'Get the weather',
                    'input_schema' => [
                        'type' => 'object',
                        'properties' => [
                            'location' => ['type' => 'string'],
                        ],
                    ],
                ],
            ];

            $provider->chat([Message::user('What is the weather?')], ['tools' => $tools]);

            expect($provider->lastPayload['tools'])->toBe([
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_weather',
                        'description' => 'Get the weather',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'location' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ]);
        });

        it('handles tool result messages', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockResponse = [
                'choices' => [['message' => ['content' => 'The weather is sunny.'], 'finish_reason' => 'stop']],
            ];

            $messages = [
                Message::user('What is the weather?'),
                Message::assistant('', [new ToolCall('call_123', 'get_weather', ['location' => 'London'])]),
                Message::toolResult('call_123', 'Sunny, 22C'),
            ];

            $provider->chat($messages);

            expect($provider->lastPayload['messages'][2]['role'])->toBe('tool');
            expect($provider->lastPayload['messages'][2]['content'])->toBe('Sunny, 22C');
            expect($provider->lastPayload['messages'][2]['tool_call_id'])->toBe('call_123');
        });

        it('handles assistant messages with tool calls', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockResponse = [
                'choices' => [['message' => ['content' => 'Done'], 'finish_reason' => 'stop']],
            ];

            $messages = [
                Message::assistant('', [new ToolCall('call_456', 'search', ['query' => 'test'])]),
            ];

            $provider->chat($messages);

            $assistantMsg = $provider->lastPayload['messages'][0];
            expect($assistantMsg['role'])->toBe('assistant');
            expect($assistantMsg['tool_calls'][0]['id'])->toBe('call_456');
            expect($assistantMsg['tool_calls'][0]['type'])->toBe('function');
            expect($assistantMsg['tool_calls'][0]['function']['name'])->toBe('search');
            expect($assistantMsg['tool_calls'][0]['function']['arguments'])->toBe('{"query":"test"}');
        });

        it('returns response with tool calls from API', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockResponse = [
                'choices' => [
                    [
                        'message' => [
                            'content' => '',
                            'tool_calls' => [
                                [
                                    'id' => 'call_789',
                                    'type' => 'function',
                                    'function' => [
                                        'name' => 'get_weather',
                                        'arguments' => '{"location":"Paris"}',
                                    ],
                                ],
                            ],
                        ],
                        'finish_reason' => 'tool_calls',
                    ],
                ],
            ];

            $response = $provider->chat([Message::user('Weather in Paris?')]);

            expect($response->hasToolCalls())->toBeTrue();
            expect($response->toolCalls[0]->name)->toBe('get_weather');
            expect($response->toolCalls[0]->arguments)->toBe(['location' => 'Paris']);
        });

        it('handles multimodal messages with image URL', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockResponse = [
                'choices' => [['message' => ['content' => 'I see a cat'], 'finish_reason' => 'stop']],
            ];

            $message = Message::userWithImage('What is this?', 'https://example.com/cat.jpg');
            $provider->chat([$message]);

            $apiMsg = $provider->lastPayload['messages'][0];
            expect($apiMsg['role'])->toBe('user');
            expect($apiMsg['content'])->toBeArray();
            expect($apiMsg['content'][0])->toBe(['type' => 'text', 'text' => 'What is this?']);
            expect($apiMsg['content'][1])->toBe([
                'type' => 'image_url',
                'image_url' => ['url' => 'https://example.com/cat.jpg'],
            ]);
        });

        it('handles multimodal messages with base64 image', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockResponse = [
                'choices' => [['message' => ['content' => 'I see a cat'], 'finish_reason' => 'stop']],
            ];

            $message = Message::userWithImage('What is this?', 'abc123base64data', 'image/png');
            $provider->chat([$message]);

            $apiMsg = $provider->lastPayload['messages'][0];
            expect($apiMsg['content'][1])->toBe([
                'type' => 'image_url',
                'image_url' => ['url' => 'data:image/png;base64,abc123base64data'],
            ]);
        });

        it('handles outputSchema option for structured output', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockResponse = [
                'choices' => [['message' => ['content' => '{"name":"test"}'], 'finish_reason' => 'stop']],
            ];

            $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
            $provider->chat([Message::user('Give me JSON')], ['outputSchema' => $schema]);

            expect($provider->lastPayload['response_format'])->toBe([
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'response',
                    'schema' => $schema,
                ],
            ]);
        });
    });

    describe('stream', function () {
        it('yields StreamChunk for content deltas', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockStreamData = implode("\n", [
                'data: {"choices":[{"delta":{"content":"Hello"},"finish_reason":null}]}',
                'data: {"choices":[{"delta":{"content":" world"},"finish_reason":null}]}',
                'data: {"choices":[{"delta":{},"finish_reason":"stop"}]}',
                'data: [DONE]',
            ]);

            $chunks = [];
            foreach ($provider->stream([Message::user('Hi')]) as $chunk) {
                $chunks[] = $chunk;
            }

            expect($chunks)->toHaveCount(3);
            expect($chunks[0])->toBeInstanceOf(StreamChunk::class);
            expect($chunks[0]->text)->toBe('Hello');
            expect($chunks[0]->isComplete)->toBeFalse();
            expect($chunks[1]->text)->toBe(' world');
            expect($chunks[1]->isComplete)->toBeFalse();
            expect($chunks[2]->text)->toBe('');
            expect($chunks[2]->isComplete)->toBeTrue();
        });

        it('sets stream flag in payload', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockStreamData = 'data: {"choices":[{"delta":{},"finish_reason":"stop"}]}';

            iterator_to_array($provider->stream([Message::user('Hi')]));

            expect($provider->lastPayload['stream'])->toBeTrue();
        });

        it('handles empty stream gracefully', function () {
            $provider = new TestableGrokProvider('test-key');
            $provider->mockStreamData = 'data: [DONE]';

            $chunks = iterator_to_array($provider->stream([Message::user('Hi')]));

            expect($chunks)->toHaveCount(0);
        });
    });
});
