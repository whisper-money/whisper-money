<?php

use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AutomationRuleService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'encrypted' => false,
    ]);
    $this->service = app(AutomationRuleService::class);
});

/**
 * The same fixtures drive the TS rule-engine-parity.test.ts, so both engines
 * must agree on every case here — locking client and server evaluation together.
 *
 * @return array<string, array{0: array<string, mixed>}>
 */
function ruleEngineParityFixtures(): array
{
    $fixtures = json_decode(
        file_get_contents(__DIR__.'/../Fixtures/rule-engine-parity.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return collect($fixtures)
        ->mapWithKeys(fn (array $fixture): array => [$fixture['name'] => [$fixture]])
        ->all();
}

it('evaluates the shared parity fixtures the same way the client engine does', function (array $fixture) {
    $rule = AutomationRule::factory()->create([
        'user_id' => $this->user->id,
        'priority' => 1,
        'rules_json' => $fixture['rule'],
        'action_category_id' => null,
    ]);

    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => $fixture['transaction']['description'],
        'creditor_name' => $fixture['transaction']['creditor_name'],
        'debtor_name' => $fixture['transaction']['debtor_name'],
        'notes' => $fixture['transaction']['notes'],
        // Fixture amounts are in dollars, the unit the rule engine compares
        // after dividing the stored minor units by 100.
        'amount' => (int) round($fixture['transaction']['amount'] * 100),
    ]);

    expect($this->service->ruleMatches($rule, $transaction))->toBe($fixture['expected']);
})->with(ruleEngineParityFixtures());
