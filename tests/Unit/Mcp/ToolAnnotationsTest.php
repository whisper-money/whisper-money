<?php

use App\Mcp\Servers\WhisperMoneyServer;
use Laravel\Mcp\Server\Tool;

/**
 * The ChatGPT app directory submission declares these hints per tool, so the
 * served annotations must keep matching chatgpt-app-submission.json.
 */
$readOnly = [
    'search_transactions',
    'spending_by_category',
    'get_cashflow',
    'get_net_worth',
    'list_accounts',
    'list_categories',
    'list_labels',
    'list_budgets',
    'list_automation_rules',
    'list_spaces',
];

$destructive = [
    'delete_transaction',
    'delete_category',
    'delete_label',
    'delete_automation_rule',
    'delete_budget',
];

/**
 * The ChatGPT submission portal caps each tool description at 200 characters,
 * so a longer one cannot be entered in the form. Detail that does not fit
 * belongs in the server instructions or a schema field description.
 */
it('keeps every tool description within the submission form limit', function () {
    /** @var array<int, class-string<Tool>> $tools */
    $tools = (new ReflectionClass(WhisperMoneyServer::class))->getDefaultProperties()['tools'];

    foreach ($tools as $class) {
        $tool = new $class;

        expect(mb_strlen((string) $tool->description()))
            ->toBeLessThanOrEqual(200, "description for {$tool->name()}");
    }
});

it('declares all three MCP hints on every tool', function () use ($readOnly, $destructive) {
    /** @var array<int, class-string<Tool>> $tools */
    $tools = (new ReflectionClass(WhisperMoneyServer::class))->getDefaultProperties()['tools'];

    expect($tools)->toHaveCount(count($readOnly) + 19);

    foreach ($tools as $class) {
        $tool = new $class;
        $annotations = $tool->annotations();
        $name = $tool->name();

        expect($annotations)->toHaveKeys(['readOnlyHint', 'destructiveHint', 'openWorldHint'])
            ->and($annotations['openWorldHint'])->toBeFalse()
            ->and($annotations['readOnlyHint'])->toBe(in_array($name, $readOnly, true), "readOnlyHint for {$name}")
            ->and($annotations['destructiveHint'])->toBe(in_array($name, $destructive, true), "destructiveHint for {$name}");
    }
});
