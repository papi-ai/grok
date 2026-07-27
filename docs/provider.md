# Grok (xAI)

Grok provider for PapiAI, powered by xAI.

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

## Models

```php
GrokProvider::MODEL_GROK_3      // 'grok-3' (default)
GrokProvider::MODEL_GROK_3_MINI // 'grok-3-mini' (fast)
GrokProvider::MODEL_GROK_2      // 'grok-2'
```

## Capabilities

| Capability | Supported |
|---|---|
| Chat | Yes |
| Streaming | Yes |
| Tool calling | Yes |
| Vision | Yes |
| Structured output | Yes |

## Requirements

- PHP 8.2+
- `ext-curl`
- `papi-ai/papi-core` ^0.14
