<?php

use App\Mcp\Servers\WhisperMoneyServer;
use Laravel\Mcp\Server\Tool;

/**
 * Every tool must declare all three MCP hints, so a client can tell a read from
 * a write and an edit from a deletion without calling anything first.
 */
$readOnly = [
    'search_transactions',
    'spending_by_category',
    'spending_by_label',
    'aggregate_transactions',
    'get_credit_card_usage',
    'get_monthly_savings',
    'get_cashflow',
    'get_net_worth',
    'get_runway',
    'list_accounts',
    'list_automation_rules',
    'list_balances',
    'list_budgets',
    'list_categories',
    'list_labels',
    'list_recurring_series',
    'list_savings_goals',
    'list_spaces',
    'list_dashboards',
    'get_widget_sdk',
    'get_widget',
];

/**
 * Only irreversible tools are destructive. Creating, updating, categorizing and
 * labelling can all be undone by another call, and marking them destructive
 * makes a client ask for confirmation on every edit. Merging a split back
 * deletes its parts, and the category, labels and notes set on each of them,
 * for good. split_transaction stays marked as before: it rewrites an existing
 * transaction into parts, and a merge brings the original back but not the
 * parts.
 */
$destructive = [
    'delete_transaction',
    'delete_category',
    'delete_label',
    'delete_automation_rule',
    'delete_balance',
    'delete_budget',
    'split_transaction',
    'merge_transaction_splits',
    // Deletes the placement, its size and position with it. update_widget is
    // not here: every revision is kept and can be gone back to.
    'remove_widget',
];

it('declares all three MCP hints on every tool', function () use ($readOnly, $destructive) {
    /** @var array<int, class-string<Tool>> $tools */
    $tools = (new ReflectionClass(WhisperMoneyServer::class))->getDefaultProperties()['tools'];

    expect($tools)->toHaveCount(count($readOnly) + 31);

    foreach ($tools as $class) {
        $tool = app($class);
        $annotations = $tool->annotations();
        $name = $tool->name();

        expect($annotations)->toHaveKeys(['readOnlyHint', 'destructiveHint', 'openWorldHint'])
            ->and($annotations['openWorldHint'])->toBe(! in_array($name, $readOnly, true), "openWorldHint for {$name}")
            ->and($annotations['readOnlyHint'])->toBe(in_array($name, $readOnly, true), "readOnlyHint for {$name}")
            ->and($annotations['destructiveHint'])->toBe(in_array($name, $destructive, true), "destructiveHint for {$name}");
    }
});

it('serves every tool the hint lists name', function () use ($readOnly, $destructive) {
    /** @var array<int, class-string<Tool>> $tools */
    $tools = (new ReflectionClass(WhisperMoneyServer::class))->getDefaultProperties()['tools'];

    $served = array_map(fn (string $class): string => app($class)->name(), $tools);

    expect(array_diff(array_merge($readOnly, $destructive), $served))->toBeEmpty();
});
