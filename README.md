# PapiAI Grok Provider

[![CI](https://github.com/papi-ai/grok/workflows/CI/badge.svg)](https://github.com/papi-ai/grok/actions?query=workflow%3ACI) [![Latest Version](https://img.shields.io/packagist/v/papi-ai/grok.svg)](https://packagist.org/packages/papi-ai/grok) [![Total Downloads](https://img.shields.io/packagist/dt/papi-ai/grok.svg)](https://packagist.org/packages/papi-ai/grok) [![PHP Version](https://img.shields.io/packagist/php-v/papi-ai/grok.svg)](https://packagist.org/packages/papi-ai/grok) [![License](https://img.shields.io/packagist/l/papi-ai/grok.svg)](https://packagist.org/packages/papi-ai/grok)

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
GrokProvider::MODEL_GROK_4_5  // 'grok-4.5' (default)
GrokProvider::MODEL_GROK_4_3  // 'grok-4.3'
```

The `MODEL_GROK_2`, `MODEL_GROK_3` and `MODEL_GROK_3_MINI` constants are still shipped but deprecated. Grok 2 is retired, and the Grok 3 pair was retired on 15 May 2026: requests silently redirect to `grok-4.3` and bill at its rates, so pin a live model explicitly.

## Features

- Tool/function calling
- Vision/multimodal support
- Structured output (JSON mode)
- Streaming support

## License

MIT
