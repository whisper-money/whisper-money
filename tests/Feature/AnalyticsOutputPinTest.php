<?php

use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\GetCashflow;
use App\Mcp\Tools\GetNetWorth;
use App\Mcp\Tools\SpendingByCategory;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\AnalyticsPinWorld;

/*
 * Characterization test: it pins, byte for byte, what the internal analytics
 * endpoints and the MCP analytics tools answer for one invented household, so
 * that moving their maths out of the controllers can prove it changed nothing.
 * The owner's connector reads these tools every day.
 *
 * The golden file was generated from the controllers before the move, and is
 * only read here. Every id comes from a counter, so the only thing that can
 * vary between runs is the personal spaces, which are renamed.
 */

beforeEach(function () {
    Http::fake();

    // Mid-March: March is the current period, February the previous one.
    $this->travelTo(Carbon::parse('2026-03-18 12:00:00'));
});

it('answers exactly what it answered before the analytics moved out of the controllers', function () {
    $outputs = AnalyticsPinWorld::outputs(AnalyticsPinWorld::build());
    $golden = json_decode((string) file_get_contents(AnalyticsPinWorld::GOLDEN), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($outputs))->toBe(array_keys($golden));

    foreach ($outputs as $call => $bytes) {
        expect($bytes)->toBe($golden[$call], "'{$call}' changed");
    }
});

it('never lets the partner\'s rows into an answer', function () {
    $world = AnalyticsPinWorld::build();
    $outputs = AnalyticsPinWorld::outputs($world);

    foreach ($outputs as $call => $bytes) {
        expect($bytes)
            ->not->toContain('Partner', "'{$call}' read a partner's row")
            ->not->toContain('888888', "'{$call}' read a partner's balance");
    }
});

it('keeps the screens and the tools free of the public API\'s range caps', function () {
    $user = AnalyticsPinWorld::build()['user'];
    $this->actingAs($user);

    // 900 days: the public API refuses a daily net worth past 366.
    $long = ['from' => '2023-10-01', 'to' => '2026-03-18'];
    $days = (int) Carbon::parse($long['from'])->diffInDays(Carbon::parse($long['to'])) + 1;
    // 15 years: past the 120 months of a monthly net worth or spending, and the 24 of a cashflow.
    $longer = ['from' => '2011-01-01', 'to' => '2026-03-18'];

    expect($this->getJson('/api/dashboard/net-worth-daily-evolution?'.http_build_query($long))->assertOk()->json('data'))->toHaveCount($days)
        ->and($this->getJson('/api/dashboard/net-worth-evolution?'.http_build_query($longer))->assertOk()->json('data'))->toHaveCount(183)
        ->and($this->getJson('/api/cashflow/trend?'.http_build_query($longer))->assertOk()->json('data'))->toHaveCount(24)
        ->and(mcpPayload(WhisperMoneyServer::actingAs($user)->tool(GetNetWorth::class, [...$long, 'granularity' => 'daily'])->assertOk())['evolution']['data'])->toHaveCount($days)
        ->and(mcpPayload(WhisperMoneyServer::actingAs($user)->tool(GetNetWorth::class, $longer)->assertOk())['evolution']['data'])->toHaveCount(183)
        ->and(mcpPayload(WhisperMoneyServer::actingAs($user)->tool(GetCashflow::class, $longer)->assertOk())['trend']['data'])->toHaveCount(24);

    WhisperMoneyServer::actingAs($user)->tool(SpendingByCategory::class, $longer)->assertOk();
});
