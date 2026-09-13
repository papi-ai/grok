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

/**
 * Every xAI model this package knows.
 *
 * The enum is the source of truth: the `MODEL_*` constants on GrokProvider alias its values, so both
 * spell the same string. It lets a watchdog enumerate what we ship instead of parsing source, and
 * each case knows whether it has been retired and what replaces it.
 *
 * An ID we have not heard of is not an error: `tryFrom()` returns null and callers may pass it
 * straight through, since next month's model is far likelier than last year's.
 *
 * @see https://docs.x.ai/developers/models
 */
enum GrokModel: string
{
    case Grok46 = 'grok-4.6';
    case Grok45 = 'grok-4.5';
    case Grok43 = 'grok-4.3';
    /** @deprecated Retired 15 May 2026; requests silently redirect to grok-4.3 and bill at its rates. */
    case Grok3 = 'grok-3';
    /** @deprecated Retired; no longer listed by xAI. */
    case Grok3Mini = 'grok-3-mini';
    /** @deprecated Retired; no longer listed by xAI. */
    case Grok2 = 'grok-2';

    /**
     * Whether the provider has retired this model.
     */
    public function isDeprecated(): bool
    {
        return match ($this) {
            self::Grok3 => true,
            self::Grok3Mini => true,
            self::Grok2 => true,
            default => false,
        };
    }

    /**
     * The published retirement date, ISO formatted, where the provider gave one.
     */
    public function retiredOn(): ?string
    {
        return match ($this) {
            self::Grok3 => '2026-05-15',
            default => null,
        };
    }

    /**
     * What to use instead, for retired models that have a successor here.
     */
    public function replacement(): ?self
    {
        return match ($this) {
            self::Grok3 => self::Grok43,
            self::Grok3Mini => self::Grok43,
            self::Grok2 => self::Grok43,
            default => null,
        };
    }
}
