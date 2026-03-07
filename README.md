# PapiAI Grok Provider

[![Tests](https://github.com/papi-ai/grok/workflows/CI/badge.svg)](https://github.com/papi-ai/grok/actions?query=workflow%3ACI)

Grok (xAI) provider for [PapiAI](https://github.com/papi-ai/papi-core) - A simple but powerful PHP library for building AI agents.

## Installation

```bash
composer require papi-ai/grok
```

## Usage

```php
use PapiAI\Core\Agent;
use PapiAI\Grok\GrokProvider;

$provider = new GrokProvider(
    apiKey: $_ENV['XAI_API_KEY'],
);

$agent = new Agent(
    provider: $provider,
    instructions: 'You are a helpful assistant.',
);

$response = $agent->run('Hello!');
echo $response->text;
```

## Available Models

```php
GrokProvider::MODEL_GROK_3      // 'grok-3' (default)
GrokProvider::MODEL_GROK_3_MINI // 'grok-3-mini' (fast)
GrokProvider::MODEL_GROK_2      // 'grok-2'
```

## Features

- Tool/function calling
- Vision/multimodal support
- Structured output (JSON mode)
- Streaming support

## License

MIT
