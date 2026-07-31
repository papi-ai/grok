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

use PapiAI\Core\Effort;
use PapiAI\Core\Message;
use PapiAI\Grok\GrokProvider;

/**
 * Captures the request payload so effort mapping can be asserted without HTTP.
 */
class TestableGrokEffortProvider extends GrokProvider
{
    public array $lastPayload = [];

    protected function request(array $payload): array
    {
        $this->lastPayload = $payload;

        return ['choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']]];
    }
}

describe('GrokProvider reasoning effort', function () {
    beforeEach(function () {
        $this->provider = new TestableGrokEffortProvider('test-api-key');
        $this->chat = fn (array $options) => $this->provider->chat([Message::user('hi')], $options);
    });

    it('maps the middle levels straight through', function () {
        foreach (['low', 'medium', 'high'] as $level) {
            ($this->chat)(['effort' => $level, 'model' => 'grok-4.3']);

            expect($this->provider->lastPayload['reasoning_effort'])->toBe($level);
        }
    });

    it('lets 4.3 switch reasoning off', function () {
        ($this->chat)(['effort' => 'none', 'model' => 'grok-4.3']);

        expect($this->provider->lastPayload['reasoning_effort'])->toBe('none');
    });

    it('will not claim 4.5 can switch reasoning off, because it cannot', function () {
        ($this->chat)(['effort' => 'none', 'model' => 'grok-4.5']);

        expect($this->provider->lastPayload['reasoning_effort'])->toBe('low');
    });

    it('narrows the levels above what xAI offers', function () {
        foreach (['extra-high', 'maximum'] as $level) {
            ($this->chat)(['effort' => $level, 'model' => 'grok-4.5']);

            expect($this->provider->lastPayload['reasoning_effort'])->toBe('high');
        }
    });

    it('sends nothing when the caller does not ask', function () {
        ($this->chat)([]);

        expect($this->provider->lastPayload)->not->toHaveKey('reasoning_effort');
    });

    it('rejects a level it does not recognise', function () {
        expect(fn () => ($this->chat)(['effort' => 'enormous']))
            ->toThrow(InvalidArgumentException::class, 'enormous');
    });

    it('accepts a provider-level default the call can override', function () {
        $provider = new TestableGrokEffortProvider('k', 'grok-4.3', 4096, Effort::High);

        $provider->chat([Message::user('hi')], []);
        expect($provider->lastPayload['reasoning_effort'])->toBe('high');

        $provider->chat([Message::user('hi')], ['effort' => 'low']);
        expect($provider->lastPayload['reasoning_effort'])->toBe('low');
    });
});
