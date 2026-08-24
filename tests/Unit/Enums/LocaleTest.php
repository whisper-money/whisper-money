<?php

use App\Enums\Locale;

it('detects the locale from an Accept-Language header', function (?string $header, Locale $expected) {
    expect(Locale::detectFromHeader($header))->toBe($expected);
})->with([
    'spanish with region' => ['es-ES,es;q=0.9', Locale::Spanish],
    'spanish bare' => ['es', Locale::Spanish],
    'french with region' => ['fr-FR,fr;q=0.9,en;q=0.8', Locale::French],
    'french bare' => ['fr', Locale::French],
    'english' => ['en-US,en;q=0.9', Locale::English],
    'unsupported falls back to english' => ['de-DE,de;q=0.9', Locale::English],
    'empty falls back to english' => ['', Locale::English],
    'null falls back to english' => [null, Locale::English],
]);
