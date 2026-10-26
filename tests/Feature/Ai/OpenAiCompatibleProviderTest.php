<?php

use App\Services\Ai\ReportSummarizer;

it('fails fast when an OpenAI-compatible endpoint is selected without a URL', function () {
    config([
        'ai_reports.provider' => 'openai-compatible',
        'ai.providers.openai-compatible.url' => null,
    ]);

    // The summariser swallows every failure so a report still posts, so the
    // misconfiguration surfaces as a null summary rather than an exception.
    expect(app(ReportSummarizer::class)->summarize('test-report', 'Test report.', []))->toBeNull();
});
