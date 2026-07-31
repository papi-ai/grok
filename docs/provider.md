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
GrokProvider::MODEL_GROK_4_5  // 'grok-4.5' (default)
GrokProvider::MODEL_GROK_4_3  // 'grok-4.3'
```

The `MODEL_GROK_2`, `MODEL_GROK_3` and `MODEL_GROK_3_MINI` constants are still shipped but deprecated. Grok 2 is retired, and the Grok 3 pair was retired on 15 May 2026: requests silently redirect to `grok-4.3` and bill at its rates, so pin a live model explicitly.

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
