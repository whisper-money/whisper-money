<?php

use App\Services\Ai\ReportSummarizer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Pointing AI_PROVIDER at an OpenAI-compatible endpoint (OrcaRouter, LM Studio,
 * vLLM, a gateway...) needs no application code: laravel/ai ships the driver and
 * our config already accepts any Lab case. This guards that claim — the README
 * documents it — by driving the real gateway against a faked HTTP layer.
 *
 * The report summariser is the vehicle because it is the cheapest of the three
 * AI features to invoke; the provider switch it reads is shared by all of them.
 */
it('prompts an OpenAI-compatible endpoint with a bearer token when the provider is switched to one', function () {
    config([
        'ai_reports.provider' => 'openai-compatible',
        'ai_reports.model' => 'orcarouter/auto',
        'ai.providers.openai-compatible.url' => 'https://api.orcarouter.ai/v1',
        'ai.providers.openai-compatible.key' => 'sk-orca-test',
    ]);

    Http::fake([
        'api.orcarouter.ai/*' => Http::response([
            'model' => 'orcarouter/auto',
            'choices' => [['message' => ['content' => 'Los registros bajan.'], 'finish_reason' => 'stop']],
        ]),
    ]);

    $summary = app(ReportSummarizer::class)->summarize('test-report', 'Test report.', ['weeks' => []]);

    expect($summary)->toBe('Los registros bajan.');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.orcarouter.ai/v1/chat/completions'
        && $request->hasHeader('Authorization', 'Bearer sk-orca-test')
        && $request['model'] === 'orcarouter/auto');
});

it('fails fast when an OpenAI-compatible endpoint is selected without a URL', function () {
    config([
        'ai_reports.provider' => 'openai-compatible',
        'ai.providers.openai-compatible.url' => null,
    ]);

    // The summariser swallows every failure so a report still posts, so the
    // misconfiguration surfaces as a null summary rather than an exception.
    expect(app(ReportSummarizer::class)->summarize('test-report', 'Test report.', []))->toBeNull();
});
