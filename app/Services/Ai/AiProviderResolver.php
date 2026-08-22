<?php

namespace App\Services\Ai;

use Laravel\Ai\Enums\Lab;

/**
 * Resolves the configured AI provider string into a value laravel/ai accepts
 * as a `provider:` argument (a `Lab` enum case or a named provider string).
 *
 * laravel/ai lets you reference a named openai-compatible instance by its
 * config key (e.g. `provider: 'orcarouter'`), but the app historically passes
 * every provider value through `Lab::from()`, which throws for values that are
 * not enum cases. This helper is the single tolerant place that maps a config
 * string to a `Lab` case when it has one and otherwise falls back to the raw
 * string so named providers (OrcaRouter) keep working.
 */
class AiProviderResolver
{
    /**
     * Resolve a provider config string to a `Lab` case or a named provider string.
     */
    public static function resolve(string $provider): Lab|string
    {
        return Lab::tryFrom($provider) ?? $provider;
    }
}
