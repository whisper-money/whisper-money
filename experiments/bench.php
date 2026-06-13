<?php

/**
 * Deterministic coverage benchmark for the AI rule-suggestion pipeline.
 *
 * It exercises the REAL aggregator + guard (so any change to those, or to
 * config/ai_suggestions.php, is reflected here), but replaces the live Gemini
 * call with a frozen "oracle" model proxy. The oracle assumes an ideal model:
 * for every group the aggregator sends, it proposes the most powerful safe
 * match token derivable from that group and maps it to a (new) category so the
 * category step never binds. What the guard then accepts is the deterministic
 * ceiling that the non-AI parts of the pipeline allow.
 *
 *  - reachable_tx : uncategorized tx living in groups the aggregator sends
 *                   (hard ceiling — the model can never categorize beyond this)
 *  - oracle_tx    : distinct uncategorized tx an ideal model+guard would
 *                   categorize given those groups (PRIMARY metric)
 *
 * The oracle token heuristic is FROZEN: improvements must come from real
 * pipeline code (aggregator/guard/config), never from editing this file.
 */

use App\Models\User;
use App\Services\Ai\Contracts\TransactionMatcher;
use App\Services\Ai\RuleSuggestionAggregator;
use App\Services\Ai\RuleSuggestionGuard;

$email = getenv('BENCH_USER') ?: 'victoor89@gmail.com';

$user = User::query()->where('email', $email)->first();

if ($user === null) {
    fwrite(STDERR, "BENCH user not found: {$email}\n");
    exit(1);
}

/** @var RuleSuggestionAggregator $aggregator */
$aggregator = app(RuleSuggestionAggregator::class);
/** @var RuleSuggestionGuard $guard */
$guard = app(RuleSuggestionGuard::class);
/** @var TransactionMatcher $matcher */
$matcher = app(TransactionMatcher::class);

$total = $matcher->total($user);
$groups = $aggregator->groupsFor($user);
$categories = $aggregator->categoryOptions($user);

$reachable = array_sum(array_column($groups, 'count'));
$overbroad = (float) config('ai_suggestions.overbroad_fraction');

/**
 * Frozen list of structural Spanish-bank / geographic noise words that carry no
 * merchant or category identity. Excluding them stops the oracle from picking a
 * generic descriptor (e.g. "pago", "tarjeta") that would span unrelated
 * merchants and wildly overstate reachable coverage. FROZEN — do not tune.
 *
 * @var array<string, true>
 */
$STOP = array_fill_keys([
    'pago', 'pagos', 'compra', 'compras', 'recibo', 'recibos', 'adeudo', 'adeudos',
    'transferencia', 'transferencias', 'realizada', 'realizado', 'emitida', 'recibida',
    'devolucion', 'devolución', 'tarjeta', 'tarj', 'debito', 'débito', 'credito', 'crédito',
    'efectivo', 'cajero', 'cajeros', 'ingreso', 'ingresos', 'traspaso', 'traspasos',
    'para', 'por', 'con', 'del', 'las', 'los', 'una', 'uno', 'unos', 'unas', 'que',
    'num', 'nro', 'ref', 'concepto', 'fecha', 'importe', 'cuenta', 'banco', 'comision', 'comisión',
    'madrid', 'barcelona', 'sevilla', 'valencia', 'malaga', 'bilbao', 'esp', 'espana', 'españa',
    'spain', 'www', 'http', 'https', 'eur', 'usd', 'slu', 'mediante',
], true);

/**
 * Frozen oracle: pick the most distinctive safe match token for a group.
 * - counterparty fields: the whole (clean) key, matched with `equals`.
 * - description: the non-noise key word that matches the most transactions
 *   without tripping the over-broad guard (ties broken by longer word). The
 *   noise stoplist keeps the choice on a merchant/category-bearing token,
 *   approximating a competent model reading a messy bank descriptor.
 *
 * @return array{0:string,1:string}|null [operator, token]
 */
$pickToken = function (array $group) use ($matcher, $user, $total, $overbroad, $STOP): ?array {
    $field = $group['field'];

    if ($field !== 'description') {
        $token = $group['key'];
        $count = $matcher->countMatching($user, $field, 'equals', $token);

        if ($count >= 1 && $total > 0 && ($count / $total) <= $overbroad) {
            return ['equals', $token];
        }

        return null;
    }

    $words = array_values(array_unique(array_filter(
        explode(' ', $group['key']),
        fn (string $w): bool => mb_strlen($w) >= 3 && ! isset($STOP[$w]),
    )));

    $best = null;
    $bestCount = 0;
    $bestLen = 0;

    foreach ($words as $word) {
        $count = $matcher->countMatching($user, 'description', 'contains', $word);

        if ($count < 1 || ($total > 0 && ($count / $total) > $overbroad)) {
            continue;
        }

        $len = mb_strlen($word);

        if ($count > $bestCount || ($count === $bestCount && $len > $bestLen)) {
            $best = $word;
            $bestCount = $count;
            $bestLen = $len;
        }
    }

    return $best === null ? null : ['contains', $best];
};

$rawSuggestions = [];

foreach ($groups as $group) {
    $picked = $pickToken($group);

    if ($picked === null) {
        continue;
    }

    [$operator, $token] = $picked;

    $rawSuggestions[] = [
        'group_key' => $group['key'],
        'match_field' => $group['field'],
        'match_operator' => $operator,
        'match_token' => $token,
        // Propose a new category so the guard's category step never binds —
        // we are measuring the token/clustering ceiling, not category mapping.
        'category_id' => '',
        'new_category_name' => mb_substr('grp '.$group['key'], 0, 255),
        'new_category_direction' => $group['direction'] === 'inflow' ? 'inflow' : 'outflow',
        'confidence' => 1.0,
    ];
}

$validated = $guard->validate($user, $rawSuggestions, $categories);

$matchedIds = [];

foreach ($validated as $suggestion) {
    $ids = $matcher
        ->matching($user, $suggestion['match_field'], $suggestion['match_operator'], $suggestion['match_token'])
        ->pluck('id');

    foreach ($ids as $id) {
        $matchedIds[$id] = true;
    }
}

$oracleTx = count($matchedIds);
$coverage = $total > 0 ? round($oracleTx / $total * 100, 2) : 0.0;

echo 'METRIC oracle_tx='.$oracleTx."\n";
echo 'METRIC reachable_tx='.$reachable."\n";
echo 'METRIC total_uncat='.$total."\n";
echo 'METRIC groups_sent='.count($groups)."\n";
echo 'METRIC validated_count='.count($validated)."\n";
echo 'METRIC coverage_pct='.$coverage."\n";
