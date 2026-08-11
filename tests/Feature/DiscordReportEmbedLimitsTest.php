<?php

use App\Ai\Agents\ReportSummaryAgent;
use App\Services\Discord\DiscordWebhook;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

/**
 * Discord rejects an entire webhook payload when an embed field value is longer
 * than 1024 characters or a description longer than 4096 — the report would just
 * stop appearing in the channel, with only a logged 400 behind it. The legend and
 * "how to read it" fields sit closest to that cap, so every scheduled report is
 * measured here, and the trimming that saves an over-long one is covered too.
 */
beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.experiment.started_at' => '2026-06-01',
        'services.discord.webhook_url' => 'https://discord.test/hook',
        'services.discord.ai_cohort_webhook_url' => 'https://discord.test/hook',
    ]);

    Http::fake(['discord.test/*' => Http::response('', 204)]);
    ReportSummaryAgent::fake(['Sin cambios relevantes frente al periodo anterior.']);
});

it('keeps every scheduled report inside Discord\'s embed limits', function (string $command) {
    if ($command === 'stats:daily-report') {
        bindMockStripeClientForStats(['active' => [], 'trialing' => []]);
    }

    artisan($command)->assertSuccessful();

    $embed = null;

    Http::assertSent(function ($request) use (&$embed) {
        $embed = $request['embeds'][0];

        return true;
    });

    expect(mb_strlen($embed['description'] ?? ''))->toBeLessThanOrEqual(4096);

    foreach ($embed['fields'] ?? [] as $field) {
        expect(mb_strlen($field['value']))->toBeLessThanOrEqual(1024)
            // The webhook trims over-long text and marks it with an ellipsis, so
            // a trimmed field means the copy itself has to be shortened.
            ->and($field['value'])->not->toEndWith('…');
    }
})->with([
    'stats:daily-report',
    'stats:subscription-funnel',
    'stats:ai-cohort-report',
]);

it('trims over-long embed text instead of letting Discord reject the report', function () {
    (new DiscordWebhook('https://discord.test/hook'))->send('', [[
        'title' => 'Informe',
        'description' => str_repeat('a', 4106),
        'fields' => [
            ['name' => 'Leyenda', 'value' => str_repeat('b', 1034), 'inline' => false],
            ['name' => 'Corto', 'value' => 'cabe de sobra', 'inline' => false],
        ],
    ]]);

    Http::assertSent(function ($request) {
        $embed = $request['embeds'][0];

        return mb_strlen($embed['description']) === 4096
            && mb_strlen($embed['fields'][0]['value']) === 1024
            && str_ends_with($embed['fields'][0]['value'], '…')
            && $embed['fields'][1]['value'] === 'cabe de sobra';
    });
});
