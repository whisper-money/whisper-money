<?php

use App\Enums\CategorySource;
use App\Jobs\ApplySingleAutomationRuleJob;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AutomationRuleApplier;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\withHeaders;

/**
 * @return array<string, string>
 */
function rulesWriter(User $user): array
{
    return apiV1Bearer($user, ['api:read', 'api:write']);
}

function sharedRuleSpace(User $member, User $owner): Space
{
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    return $space;
}

/**
 * A grocery rule over transactions that already exist: they are created
 * before the rule on purpose, since rules only run on transactions created
 * after them, which is the gap the apply endpoint fills.
 *
 * @return array{0: AutomationRule, 1: Category, 2: Label}
 */
function groceryRuleOverExistingTransactions(User $user, int $matchingCount = 2): array
{
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Essentials']);

    Transaction::factory()->count($matchingCount)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'Grocery Store',
        'amount' => -1_000,
    ]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'description' => 'Coffee Shop',
        'amount' => -500,
    ]);

    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $category->id,
    ]);
    $rule->labels()->attach($label->id);

    return [$rule, $category, $label];
}

/**
 * @return array<string, mixed>
 */
function groceryConditions(): array
{
    return ['and' => [['in' => ['mercadona', ['var' => 'description']]], ['<' => [['var' => 'amount'], 0]]]];
}

/**
 * A condition wrapped in $levels negations, each one a level of nesting.
 *
 * @return array<string, mixed>
 */
function deeplyNegatedConditions(int $levels): array
{
    $conditions = ['in' => ['mercadona', ['var' => 'description']]];

    foreach (range(1, $levels) as $level) {
        $conditions = ['!' => $conditions];
    }

    return $conditions;
}

/**
 * An "or" of $count "contains" conditions, three JsonLogic nodes each.
 *
 * @return array<string, mixed>
 */
function manyConditions(int $count): array
{
    return ['or' => array_map(fn (int $index): array => ['in' => ["shop {$index}", ['var' => 'description']]], range(1, $count))];
}

describe('list', function () {
    it('lists the rules of the personal space in evaluation order', function () {
        $user = User::factory()->create();
        $label = Label::factory()->create(['user_id' => $user->id]);
        $second = AutomationRule::factory()->create(['user_id' => $user->id, 'priority' => 5, 'title' => 'B rule']);
        $third = AutomationRule::factory()->create(['user_id' => $user->id, 'priority' => 5, 'title' => 'C rule']);
        $first = AutomationRule::factory()->create([
            'user_id' => $user->id,
            'priority' => 1,
            'title' => 'Groceries',
            'rules_json' => groceryConditions(),
            'action_note' => 'Weekly shop',
            'created_at' => '2026-01-15 09:30:00',
        ]);
        $first->labels()->attach($label);
        // Set after the attach, which touches the rule.
        AutomationRule::query()->whereKey($first->id)->update(['updated_at' => '2026-01-20 18:05:00']);
        AutomationRule::factory()->create(['user_id' => User::factory()]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/automation-rules')
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$first->id, $second->id, $third->id])
            ->assertJsonPath('data.0', [
                'id' => $first->id,
                'space_id' => $user->personalSpace->id,
                'title' => 'Groceries',
                'priority' => 1,
                'conditions' => groceryConditions(),
                'action_category_id' => null,
                'action_label_ids' => [$label->id],
                'action_note' => 'Weekly shop',
                'origin' => 'user',
                'owned' => true,
                'created_at' => '2026-01-15T09:30:00+00:00',
                'updated_at' => '2026-01-20T18:05:00+00:00',
            ]);
    });

    it('sends the conditions as an object even when a rule has none or stored them encoded', function () {
        $user = User::factory()->create();
        $empty = AutomationRule::factory()->ai()->create(['user_id' => $user->id, 'priority' => 1, 'rules_json' => []]);
        $encoded = AutomationRule::factory()->create(['user_id' => $user->id, 'priority' => 2, 'rules_json' => json_encode(groceryConditions())]);

        $response = withHeaders(apiV1Bearer($user))->getJson('/api/v1/automation-rules')->assertOk();

        expect($response->getContent())->toContain('"conditions":{}')
            ->and($response->json('data.0.id'))->toBe($empty->id)
            ->and($response->json('data.0.origin'))->toBe('ai')
            ->and($response->json('data.1.id'))->toBe($encoded->id)
            ->and($response->json('data.1.conditions'))->toBe(groceryConditions());
    });

    it('lists a shared space when asked, and refuses one the user cannot access', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedRuleSpace($user, $owner);
        $theirs = AutomationRule::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        AutomationRule::factory()->create(['user_id' => $user->id]);
        $headers = apiV1Bearer($user);

        withHeaders($headers)
            ->getJson('/api/v1/automation-rules?space='.$space->id)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$theirs->id])
            ->assertJsonPath('data.0.owned', false);

        withHeaders($headers)
            ->getJson('/api/v1/automation-rules?space='.Space::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('space');
    });

    it('pages through the rules with a cursor', function () {
        $user = User::factory()->create();
        $rules = collect(range(1, 3))->map(fn (int $priority): AutomationRule => AutomationRule::factory()->create([
            'user_id' => $user->id,
            'priority' => $priority,
        ]));
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson('/api/v1/automation-rules?limit=2')
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('data.*.id', [$rules[0]->id, $rules[1]->id]);

        withHeaders($headers)->getJson('/api/v1/automation-rules?limit=2&starting_after='.$rules[1]->id)
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$rules[2]->id]);

        withHeaders($headers)->getJson('/api/v1/automation-rules?limit=2&ending_before='.$rules[2]->id)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$rules[0]->id, $rules[1]->id]);

        withHeaders($headers)->getJson('/api/v1/automation-rules?starting_after='.AutomationRule::factory()->create(['user_id' => User::factory()])->id)
            ->assertBadRequest()
            ->assertJsonPath('code', 'invalid_cursor');
    });
});

describe('show', function () {
    it('shows a rule of the space, and 404s on someone else\'s', function () {
        $user = User::factory()->create();
        $rule = AutomationRule::factory()->create(['user_id' => $user->id]);
        $headers = apiV1Bearer($user);

        withHeaders($headers)
            ->getJson('/api/v1/automation-rules/'.$rule->id)
            ->assertOk()
            ->assertJsonPath('data.id', $rule->id);

        withHeaders($headers)
            ->getJson('/api/v1/automation-rules/'.AutomationRule::factory()->create(['user_id' => User::factory()])->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });
});

describe('create', function () {
    it('creates a rule with its conditions as a JSON object and its actions', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id]);
        $label = Label::factory()->create(['user_id' => $user->id]);

        $response = withHeaders(rulesWriter($user))
            ->postJson('/api/v1/automation-rules', [
                'title' => 'Groceries',
                'priority' => 10,
                'conditions' => groceryConditions(),
                'action_category_id' => $category->id,
                'action_label_ids' => [$label->id],
                'action_note' => 'Weekly shop',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Groceries')
            ->assertJsonPath('data.priority', 10)
            ->assertJsonPath('data.conditions', groceryConditions())
            ->assertJsonPath('data.action_category_id', $category->id)
            ->assertJsonPath('data.action_label_ids', [$label->id])
            ->assertJsonPath('data.action_note', 'Weekly shop')
            ->assertJsonPath('data.origin', 'user')
            ->assertJsonPath('data.space_id', $user->personalSpace->id);

        $rule = AutomationRule::query()->findOrFail($response->json('data.id'));

        expect($rule->rules_json)->toBe(groceryConditions())
            ->and($rule->user_id)->toBe($user->id);
    });

    it('refuses invalid conditions with the same checks as the AI connector', function (mixed $conditions, string $error) {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id]);

        withHeaders(rulesWriter($user))
            ->postJson('/api/v1/automation-rules', [
                'title' => 'Groceries',
                'priority' => 1,
                'conditions' => $conditions,
                'action_category_id' => $category->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.conditions.0', $error);

        expect($user->automationRules()->count())->toBe(0);
    })->with([
        'a JSON-encoded string' => [json_encode(groceryConditions()), 'conditions must be a non-empty JsonLogic object.'],
        'a list' => [[['in' => ['x', ['var' => 'description']]]], 'conditions must be a non-empty JsonLogic object.'],
        'an unsupported operator' => [['regex' => ['x', ['var' => 'description']]], 'Unsupported JsonLogic operator "regex". Supported operators: and, or, !, ==, !=, >, >=, <, <=, in, var.'],
        'an unknown variable' => [['in' => ['x', ['var' => 'merchant']]], 'Unknown rule variable "merchant". Supported variables: description, notes, creditor_name, debtor_name, account_name, bank_name, category, transaction_date, amount.'],
        'an operator in a variable default' => [['in' => ['x', ['var' => ['description', ['log' => 'x']]]]], 'A variable default must be a plain value (text, a number, a boolean or null).'],
        'a supported operator in a variable default' => [['in' => ['x', ['var' => ['description', ['var' => 'notes']]]]], 'A variable default must be a plain value (text, a number, a boolean or null).'],
        'conditions nested too deep' => [deeplyNegatedConditions(25), 'The conditions nest more than 20 levels deep.'],
        'too many conditions' => [manyConditions(200), 'The conditions have more than 500 JsonLogic nodes.'],
    ]);

    it('accepts a variable with a plain default and a rule as large as the builder makes', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id]);

        foreach ([['==' => [['var' => ['creditor_name', null]], null]], ['in' => ['x', ['var' => ['description', '']]]], deeplyNegatedConditions(15), manyConditions(100)] as $priority => $conditions) {
            withHeaders(rulesWriter($user))
                ->postJson('/api/v1/automation-rules', [
                    'title' => 'Groceries',
                    'priority' => $priority,
                    'conditions' => $conditions,
                    'action_category_id' => $category->id,
                ])
                ->assertCreated();
        }
    });

    it('caps the note and the labels a rule applies', function () {
        $user = User::factory()->create();

        withHeaders(rulesWriter($user))
            ->postJson('/api/v1/automation-rules', [
                'title' => 'Groceries',
                'priority' => 1,
                'conditions' => groceryConditions(),
                'action_note' => str_repeat('a', 10_001),
                'action_label_ids' => array_map(fn (): string => (string) Str::uuid(), range(1, 51)),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action_note', 'action_label_ids']);

        expect($user->automationRules()->count())->toBe(0);
    });

    it('refuses missing and unknown fields, ids outside the space and a rule with no action', function () {
        $user = User::factory()->create();
        $headers = rulesWriter($user);

        withHeaders($headers)
            ->postJson('/api/v1/automation-rules', ['rules_json' => groceryConditions()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'priority', 'conditions', 'rules_json']);

        withHeaders($headers)
            ->postJson('/api/v1/automation-rules', [
                'title' => 'Groceries',
                'priority' => 1,
                'conditions' => groceryConditions(),
                'action_category_id' => Category::factory()->create()->id,
                'action_label_ids' => [Label::factory()->create()->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action_category_id', 'action_label_ids.0']);

        withHeaders($headers)
            ->postJson('/api/v1/automation-rules', [
                'title' => 'Groceries',
                'priority' => 1,
                'conditions' => groceryConditions(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.action_category_id.0', 'At least one action is required: pass action_category_id and/or action_label_ids.');

        expect($user->automationRules()->count())->toBe(0);
    });

    it('refuses a read-only token', function () {
        $user = User::factory()->create();

        withHeaders(apiV1Bearer($user))
            ->postJson('/api/v1/automation-rules', [
                'title' => 'Groceries',
                'priority' => 1,
                'conditions' => groceryConditions(),
                'action_label_ids' => [Label::factory()->create(['user_id' => $user->id])->id],
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($user->automationRules()->count())->toBe(0);
    });

    it('replays a POST with the same idempotency key instead of creating a second rule', function () {
        $user = User::factory()->create();
        $headers = [...rulesWriter($user), 'Idempotency-Key' => 'create-groceries'];
        $body = [
            'title' => 'Groceries',
            'priority' => 1,
            'conditions' => groceryConditions(),
            'action_category_id' => Category::factory()->create(['user_id' => $user->id])->id,
        ];

        $first = withHeaders($headers)->postJson('/api/v1/automation-rules', $body)->assertCreated();
        $replay = withHeaders($headers)->postJson('/api/v1/automation-rules', $body)->assertCreated();

        $replay->assertHeader('Idempotent-Replayed', 'true');
        expect($replay->json('data.id'))->toBe($first->json('data.id'))
            ->and($user->automationRules()->count())->toBe(1);
    });
});

describe('update', function () {
    it('changes only the fields it is sent, replacing the conditions and labels', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id]);
        [$old, $new] = Label::factory()->count(2)->create(['user_id' => $user->id]);
        $rule = AutomationRule::factory()->create(['user_id' => $user->id, 'title' => 'Groceries', 'priority' => 3, 'action_category_id' => $category->id]);
        $rule->labels()->attach($old);
        $headers = rulesWriter($user);

        withHeaders($headers)
            ->patchJson('/api/v1/automation-rules/'.$rule->id, [
                'conditions' => groceryConditions(),
                'action_label_ids' => [$new->id],
                'action_note' => 'Weekly shop',
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Groceries')
            ->assertJsonPath('data.priority', 3)
            ->assertJsonPath('data.conditions', groceryConditions())
            ->assertJsonPath('data.action_category_id', $category->id)
            ->assertJsonPath('data.action_label_ids', [$new->id])
            ->assertJsonPath('data.action_note', 'Weekly shop');

        withHeaders($headers)
            ->patchJson('/api/v1/automation-rules/'.$rule->id, ['action_label_ids' => [], 'action_note' => null])
            ->assertOk()
            ->assertJsonPath('data.action_label_ids', [])
            ->assertJsonPath('data.action_note', null);
    });

    it('refuses to leave the rule without an action', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id]);
        $rule = AutomationRule::factory()->create(['user_id' => $user->id, 'action_category_id' => $category->id]);

        withHeaders(rulesWriter($user))
            ->patchJson('/api/v1/automation-rules/'.$rule->id, ['action_category_id' => null])
            ->assertUnprocessable()
            ->assertJsonPath('errors.action_category_id.0', 'An automation rule must keep at least one action: a category or labels.');

        expect($rule->fresh()->action_category_id)->toBe($category->id);
    });

    it('404s on another user\'s rule and refuses a read-only token', function () {
        $user = User::factory()->create();
        $rule = AutomationRule::factory()->create(['user_id' => $user->id, 'title' => 'Groceries']);

        withHeaders(rulesWriter($user))
            ->patchJson('/api/v1/automation-rules/'.AutomationRule::factory()->create(['user_id' => User::factory()])->id, ['title' => 'Mine now'])
            ->assertNotFound();

        withHeaders(apiV1Bearer($user))
            ->patchJson('/api/v1/automation-rules/'.$rule->id, ['title' => 'Renamed'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($rule->fresh()->title)->toBe('Groceries');
    });
});

describe('ownership', function () {
    it('lets a member read another member\'s rule but not change, apply or delete it', function () {
        $member = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedRuleSpace($member, $owner);
        $theirs = AutomationRule::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'title' => 'Theirs']);
        $headers = rulesWriter($member);
        $query = '?space='.$space->id;

        withHeaders($headers)
            ->getJson('/api/v1/automation-rules/'.$theirs->id.$query)
            ->assertOk();

        withHeaders($headers)
            ->patchJson('/api/v1/automation-rules/'.$theirs->id.$query, ['title' => 'Mine'])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_automation_rule_owner')
            ->assertJsonPath('message', "Automation rule {$theirs->id} belongs to another member of the space, so only they can change or delete it. You can still read it.");

        withHeaders($headers)
            ->postJson('/api/v1/automation-rules/'.$theirs->id.'/apply'.$query, ['dry_run' => false])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_automation_rule_owner');

        withHeaders($headers)
            ->deleteJson('/api/v1/automation-rules/'.$theirs->id.$query)
            ->assertForbidden()
            ->assertJsonPath('code', 'not_automation_rule_owner');

        expect($theirs->fresh()->title)->toBe('Theirs');
    });
});

describe('delete', function () {
    it('deletes a rule and leaves the transactions it categorized alone', function () {
        $user = User::factory()->create();
        [$rule, $category] = groceryRuleOverExistingTransactions($user);
        app(AutomationRuleApplier::class)->applyInSpace($rule, $user->personalSpace, dryRun: false, onlyUncategorized: true);

        withHeaders(rulesWriter($user))
            ->deleteJson('/api/v1/automation-rules/'.$rule->id)
            ->assertOk()
            ->assertExactJson(['id' => $rule->id, 'deleted' => true]);

        expect(AutomationRule::query()->find($rule->id))->toBeNull()
            ->and(Transaction::query()->where('category_id', $category->id)->count())->toBe(2);
    });

    it('404s on another user\'s rule and refuses a read-only token', function () {
        $user = User::factory()->create();
        $rule = AutomationRule::factory()->create(['user_id' => $user->id]);

        withHeaders(rulesWriter($user))
            ->deleteJson('/api/v1/automation-rules/'.AutomationRule::factory()->create(['user_id' => User::factory()])->id)
            ->assertNotFound();

        withHeaders(apiV1Bearer($user))
            ->deleteJson('/api/v1/automation-rules/'.$rule->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($rule->fresh())->not->toBeNull();
    });
});

describe('apply', function () {
    it('previews by default: reports the matches and a sample, and changes nothing', function () {
        $user = User::factory()->create();
        [$rule] = groceryRuleOverExistingTransactions($user);

        withHeaders(rulesWriter($user))
            ->postJson('/api/v1/automation-rules/'.$rule->id.'/apply')
            ->assertOk()
            ->assertJsonPath('data.status', 'preview')
            ->assertJsonPath('data.automation_rule_id', $rule->id)
            ->assertJsonPath('data.total', 2)
            ->assertJsonCount(2, 'data.sample')
            ->assertJsonPath('data.sample.*.description', ['Grocery Store', 'Grocery Store'])
            ->assertJsonPath('data.sample.0.amount', -1_000)
            ->assertJsonPath('data.applied', null)
            ->assertJsonPath('data.updated', null);

        expect(Transaction::query()->where('user_id', $user->id)->whereNotNull('category_id')->count())->toBe(0);
    });

    it('applies the rule\'s actions inline once the dry run is turned off', function () {
        $user = User::factory()->create();
        [$rule, $category, $label] = groceryRuleOverExistingTransactions($user);

        withHeaders(rulesWriter($user))
            ->postJson('/api/v1/automation-rules/'.$rule->id.'/apply', ['dry_run' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.sample', [])
            ->assertJsonPath('data.applied', 2)
            ->assertJsonPath('data.updated', 2);

        $matched = Transaction::query()->where('description', 'Grocery Store')->with('labels')->get();

        expect($matched->pluck('category_id')->unique()->all())->toBe([$category->id])
            ->and($matched->pluck('category_source')->unique()->all())->toBe([CategorySource::Rule])
            ->and($matched->filter(fn (Transaction $transaction): bool => $transaction->labels->contains($label)))->toHaveCount(2)
            ->and(Transaction::query()->where('description', 'Coffee Shop')->value('category_id'))->toBeNull();
    });

    it('overwrites existing categories only when only_uncategorized is off', function () {
        $user = User::factory()->create();
        [$rule, $category] = groceryRuleOverExistingTransactions($user, 1);
        $other = Category::factory()->create(['user_id' => $user->id]);
        Transaction::query()->where('description', 'Grocery Store')->update(['category_id' => $other->id]);
        $headers = rulesWriter($user);

        withHeaders($headers)
            ->postJson('/api/v1/automation-rules/'.$rule->id.'/apply', ['dry_run' => false])
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        expect(Transaction::query()->where('description', 'Grocery Store')->value('category_id'))->toBe($other->id);

        withHeaders($headers)
            ->postJson('/api/v1/automation-rules/'.$rule->id.'/apply', ['dry_run' => false, 'only_uncategorized' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.updated', 1);

        expect(Transaction::query()->where('description', 'Grocery Store')->value('category_id'))->toBe($category->id);
    });

    it('hands a large apply to the queue and answers 202', function () {
        Queue::fake();
        $user = User::factory()->create();
        [$rule] = groceryRuleOverExistingTransactions($user, AutomationRuleApplier::SYNC_THRESHOLD + 1);

        withHeaders(rulesWriter($user))
            ->postJson('/api/v1/automation-rules/'.$rule->id.'/apply', ['dry_run' => false])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.total', AutomationRuleApplier::SYNC_THRESHOLD + 1);

        Queue::assertPushed(ApplySingleAutomationRuleJob::class);
    });

    it('only goes over the transactions of the rule\'s space', function () {
        $user = User::factory()->create();
        $space = sharedRuleSpace($user, User::factory()->create());
        Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id])->id,
            'category_id' => null,
            'description' => 'Grocery Store',
        ]);
        [$rule] = groceryRuleOverExistingTransactions($user);
        $rule->update(['space_id' => $space->id]);

        withHeaders(rulesWriter($user))
            ->postJson('/api/v1/automation-rules/'.$rule->id.'/apply?space='.$space->id)
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    });

    it('refuses unknown and invalid fields, someone else\'s rule and a read-only token', function () {
        $user = User::factory()->create();
        [$rule] = groceryRuleOverExistingTransactions($user);

        withHeaders(rulesWriter($user))
            ->postJson('/api/v1/automation-rules/'.$rule->id.'/apply', ['dry_run' => 'maybe', 'dryrun' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dry_run', 'dryrun']);

        withHeaders(rulesWriter($user))
            ->postJson('/api/v1/automation-rules/'.AutomationRule::factory()->create(['user_id' => User::factory()])->id.'/apply')
            ->assertNotFound();

        withHeaders(apiV1Bearer($user))
            ->postJson('/api/v1/automation-rules/'.$rule->id.'/apply', ['dry_run' => false])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect(Transaction::query()->where('user_id', $user->id)->whereNotNull('category_id')->count())->toBe(0);
    });
});

describe('conditions as sent', function () {
    it('keeps the spaces and empty strings of the condition values', function () {
        $user = User::factory()->create();
        $conditions = ['and' => [['in' => [' bar ', ['var' => 'description']]], ['!=' => [['var' => 'notes'], '']]]];

        withHeaders(rulesWriter($user))
            ->postJson('/api/v1/automation-rules', [
                'title' => '  Bar  ',
                'priority' => 1,
                'conditions' => $conditions,
                'action_category_id' => Category::factory()->create(['user_id' => $user->id])->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Bar')
            ->assertJsonPath('data.conditions', $conditions);
    });

    it('refuses an empty conditions object and a priority the column cannot hold', function () {
        $user = User::factory()->create();

        withHeaders(rulesWriter($user))
            ->postJson('/api/v1/automation-rules', [
                'title' => 'Bar',
                'priority' => 3_000_000_000,
                'conditions' => (object) [],
                'action_category_id' => Category::factory()->create(['user_id' => $user->id])->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.conditions.0', 'conditions must be a non-empty JsonLogic object.')
            ->assertJsonValidationErrors('priority');
    });
});
