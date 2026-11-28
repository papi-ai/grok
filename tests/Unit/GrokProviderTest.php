<?php

declare(strict_types=1);

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

    public function callThrowForStatusCode(int $httpCode, ?array $data): never
    {
        $this->throwForStatusCode($httpCode, $data);
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

    describe('error mapping', function () {
        it('reports a wrong key as an authentication failure, though xAI answers 400 with the error as plain text', function () {
            $provider = new TestableGrokProvider('test-key');
            $body = ['code' => 'invalid-argument', 'error' => 'Incorrect API key provided. You can obtain an API key from https://console.x.ai.'];

            try {
                $provider->callThrowForStatusCode(400, $body);
            } catch (PapiAI\Core\Exception\AuthenticationException $rejected) {
            }

            expect($rejected->statusCode)->toBe(400);
            expect($rejected->responseBody)->toBe($body);
        });

        it('gives xAI\'s own message for an error sent as plain text', function () {
            $provider = new TestableGrokProvider('test-key');

            expect(fn () => $provider->callThrowForStatusCode(400, ['code' => 'invalid-argument', 'error' => 'Model not found: grok-0']))
                ->toThrow(PapiAI\Core\Exception\ProviderException::class, 'Grok API error (400): Model not found: grok-0');
        });

        it('does not take another bad request for a bad key', function () {
            $provider = new TestableGrokProvider('test-key');

            try {
                $provider->callThrowForStatusCode(400, ['error' => ['message' => 'Unsupported parameter: temperature']]);
            } catch (PapiAI\Core\Exception\ProviderException $refused) {
            }

            expect($refused)->not->toBeInstanceOf(PapiAI\Core\Exception\AuthenticationException::class);
            expect($refused->getMessage())->toBe('Grok API error (400): Unsupported parameter: temperature');
        });

        it('throws AuthenticationException on 401', function () {
            $provider = new TestableGrokProvider('test-key');

            expect(fn () => $provider->callThrowForStatusCode(401, ['error' => ['message' => 'Invalid API key']]))
                ->toThrow(\PapiAI\Core\Exception\AuthenticationException::class);
        });

        it('throws RateLimitException on 429', function () {
            $provider = new TestableGrokProvider('test-key');

            expect(fn () => $provider->callThrowForStatusCode(429, ['error' => ['message' => 'Rate limit exceeded']]))
                ->toThrow(\PapiAI\Core\Exception\RateLimitException::class);
        });

        it('throws ProviderException on 500', function () {
            $provider = new TestableGrokProvider('test-key');

            expect(fn () => $provider->callThrowForStatusCode(500, ['error' => ['message' => 'Internal server error']]))
                ->toThrow(\PapiAI\Core\Exception\ProviderException::class);
        });

        it('throws ProviderException with unknown error when data is null', function () {
            $provider = new TestableGrokProvider('test-key');

            expect(fn () => $provider->callThrowForStatusCode(500, null))
                ->toThrow(\PapiAI\Core\Exception\ProviderException::class);
        });
    });
});
