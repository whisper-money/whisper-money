<?php

use App\Ai\Agents\ReportSummaryAgent;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

test('a stats report is skipped without hitting any external service when subscriptions are disabled', function (string $command, string $expectedOutput) {
    config([
        'subscriptions.enabled' => false,
        'services.discord.webhook_url' => 'https://discord.test/webhook',
        'services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook',
    ]);

    Http::fake();
    ReportSummaryAgent::fake();

    artisan($command)
        ->expectsOutputToContain($expectedOutput)
        ->assertSuccessful();

    Http::assertNothingSent();
})->with([
    'daily stats' => ['stats:daily-report', 'Subscriptions are disabled; skipping the daily stats report.'],
    'subscription funnel' => ['stats:subscription-funnel', 'Subscriptions are disabled; skipping the subscription funnel report.'],
    'AI cohort' => ['stats:ai-cohort-report', 'Subscriptions are disabled; skipping the AI cohort report.'],
]);
