<?php

use App\Services\Ai\AiProviderResolver;
use Laravel\Ai\Enums\Lab;

it('resolves known lab provider names to their enum case', function () {
    expect(AiProviderResolver::resolve('gemini'))->toBe(Lab::Gemini);
    expect(AiProviderResolver::resolve('openai'))->toBe(Lab::OpenAI);
    expect(AiProviderResolver::resolve('openai-compatible'))->toBe(Lab::OpenAiCompatible);
    expect(AiProviderResolver::resolve('ollama'))->toBe(Lab::Ollama);
});

it('keeps named custom providers as strings so laravel/ai can look them up by config key', function () {
    expect(AiProviderResolver::resolve('orcarouter'))->toBe('orcarouter');
});
