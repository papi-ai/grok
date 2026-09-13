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

use PapiAI\Grok\GrokModel;
use PapiAI\Grok\GrokProvider;

describe('GrokModel', function () {
    it('is the source of truth the old constants alias', function () {
        expect(GrokProvider::MODEL_GROK_4_5)->toBe(GrokModel::Grok45->value);
    });

    it('ships unique IDs', function () {
        $ids = array_map(fn (GrokModel $m) => $m->value, GrokModel::cases());

        expect($ids)->toBe(array_unique($ids));
    });

    it('returns null for an ID it has not heard of, rather than throwing', function () {
        expect(GrokModel::tryFrom('not-a-model'))->toBeNull();
    });

    it('knows which models are retired, when, and what replaces them', function () {
        expect(GrokModel::Grok3->isDeprecated())->toBeTrue();
        expect(GrokModel::Grok3->retiredOn())->toBe('2026-05-15');
        expect(GrokModel::Grok3->replacement())->toBe(GrokModel::Grok43);
        expect(GrokModel::Grok46->isDeprecated())->toBeFalse();
    });
});
