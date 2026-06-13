<?php

/**
 * Ground-truth calibration: run the REAL Gemini generator through the real
 * guard and count distinct matched tx the same way bench.php does, so the
 * oracle metric can be compared against what the live model actually achieves.
 * Costs one Gemini call. Not part of the tight loop.
 */

use App\Models\User;
use App\Services\Ai\Contracts\RuleSuggestionGenerator;
use App\Services\Ai\Contracts\TransactionMatcher;
use App\Services\Ai\RuleSuggestionAggregator;
use App\Services\Ai\RuleSuggestionGuard;

$email = getenv('BENCH_USER') ?: 'victoor89@gmail.com';
$user = User::query()->where('email', $email)->first();

$aggregator = app(RuleSuggestionAggregator::class);
$guard = app(RuleSuggestionGuard::class);
$generator = app(RuleSuggestionGenerator::class);
$matcher = app(TransactionMatcher::class);

$total = $matcher->total($user);
$groups = $aggregator->groupsFor($user);
$categories = $aggregator->categoryOptions($user);

$raw = $generator->generate($groups, $categories);
$validated = $guard->validate($user, $raw, $categories);

$matchedIds = [];
foreach ($validated as $s) {
    foreach ($matcher->matching($user, $s['match_field'], $s['match_operator'], $s['match_token'])->pluck('id') as $id) {
        $matchedIds[$id] = true;
    }
}

echo 'METRIC real_tx='.count($matchedIds)."\n";
echo 'METRIC real_raw='.count($raw)."\n";
echo 'METRIC real_validated='.count($validated)."\n";
echo 'METRIC real_coverage_pct='.($total > 0 ? round(count($matchedIds) / $total * 100, 2) : 0)."\n";
