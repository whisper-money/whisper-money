<?php

use App\Enums\CategoryType;
use App\Enums\LabelSource;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateAutomationRule;
use App\Mcp\Tools\CreateBudget;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\CreateTransaction;
use App\Mcp\Tools\LabelTransaction;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\SpendingByLabel;
use App\Mcp\Tools\SplitTransaction;
use App\Mcp\Tools\UpdateAutomationRule;
use App\Mcp\Tools\UpdateLabel;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AutomationRuleService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withHeaders;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $this->account = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $this->archived = Label::factory()->archived()->create(['user_id' => $this->user->id, 'name' => 'Old trip']);
    $this->active = Label::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function archivedLabelsTransaction(array $attributes = [], array $labels = []): Transaction
{
    $transaction = Transaction::factory()->create([
        'user_id' => test()->user->id,
        'account_id' => test()->account->id,
        'category_id' => null,
        'amount' => -1000,
        'currency_code' => 'EUR',
        ...$attributes,
    ]);

    if ($labels !== []) {
        $transaction->labels()->attach(collect($labels)->pluck('id')->all());
    }

    return $transaction;
}

/**
 * @param  class-string  $tool
 * @param  array<string, mixed>  $arguments
 */
function archivedLabelsTool(string $tool, array $arguments): TestResponse
{
    $user = test()->user;
    $user->withAccessToken($user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool($tool, $arguments);
}

/**
 * @return array<string, string>
 */
function archivedLabelsApi(): array
{
    return apiV1Bearer(test()->user, ['api:read', 'api:write']);
}

describe('archiving a label', function () {
    it('archives and unarchives a label from the settings page', function () {
        Carbon::setTestNow('2026-11-17 10:00:00');

        actingAs($this->user)
            ->patch(route('labels.archived.update', $this->active), ['archived' => true])
            ->assertRedirect();

        expect($this->active->fresh()->archived_at?->toDateTimeString())->toBe('2026-11-17 10:00:00');

        actingAs($this->user)
            ->patch(route('labels.archived.update', $this->active), ['archived' => false])
            ->assertRedirect();

        expect($this->active->fresh()->archived_at)->toBeNull();
    });

    it('keeps the original date when an archived label is archived again', function () {
        $archivedAt = $this->archived->archived_at->toDateTimeString();
        Carbon::setTestNow(now()->addDays(3));

        actingAs($this->user)->patch(route('labels.archived.update', $this->archived), ['archived' => true]);

        expect($this->archived->fresh()->archived_at->toDateTimeString())->toBe($archivedAt);
    });

    it('keeps the label on the transactions that carry it', function () {
        $transaction = archivedLabelsTransaction(labels: [$this->active]);

        actingAs($this->user)->patch(route('labels.archived.update', $this->active), ['archived' => true]);

        expect($transaction->fresh()->labels->pluck('id')->all())->toBe([$this->active->id]);
    });

    it('refuses another user\'s label', function () {
        $foreign = Label::factory()->create();

        actingAs($this->user)
            ->patch(route('labels.archived.update', $foreign), ['archived' => true])
            ->assertForbidden();

        expect($foreign->fresh()->archived_at)->toBeNull();
    });

    it('refuses the label of a savings goal', function () {
        $goalLabel = Label::factory()->create(['user_id' => $this->user->id, 'source' => LabelSource::SavingsGoal]);

        actingAs($this->user)
            ->patch(route('labels.archived.update', $goalLabel), ['archived' => true])
            ->assertSessionHasErrors('archived');

        expect($goalLabel->fresh()->archived_at)->toBeNull();
    });

    it('requires the archived flag', function () {
        actingAs($this->user)
            ->patch(route('labels.archived.update', $this->active), [])
            ->assertSessionHasErrors('archived');
    });

    it('lists archived labels last on the settings page, with archived_at', function () {
        Label::factory()->create(['user_id' => $this->user->id, 'name' => 'Zoo']);

        actingAs($this->user)->get(route('labels.index'))
            ->assertInertia(fn ($page) => $page
                ->where('labels.0.name', 'Groceries')
                ->where('labels.1.name', 'Zoo')
                ->where('labels.2.name', 'Old trip')
                ->where('labels.2.archived_at', fn (?string $value): bool => $value !== null)
                ->where('labels.0.archived_at', null));
    });

    it('shares archived_at with every page through the labels prop', function () {
        actingAs($this->user)->get(route('dashboard'), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'dashboard',
            'X-Inertia-Partial-Data' => 'labels',
        ])->assertOk()
            ->assertJsonFragment(['id' => $this->archived->id, 'archived_at' => $this->archived->archived_at->toJSON()])
            ->assertJsonFragment(['id' => $this->active->id, 'archived_at' => null]);
    });
});

describe('assigning to transactions on the web', function () {
    it('refuses an archived label on a new transaction', function () {
        actingAs($this->user)->postJson(route('transactions.store'), [
            'account_id' => $this->account->id,
            'description' => 'Hotel',
            'transaction_date' => '2026-11-10',
            'amount' => -5000,
            'currency_code' => 'EUR',
            'source' => 'manually_created',
            'label_ids' => [$this->archived->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['label_ids.0' => 'is archived']);
    });

    it('assigns an archived label on an imported row (CSV import keeps history)', function () {
        $id = actingAs($this->user)->postJson(route('transactions.store'), [
            'account_id' => $this->account->id,
            'description' => 'Hotel',
            'transaction_date' => '2024-11-10',
            'amount' => -5000,
            'currency_code' => 'EUR',
            'source' => 'imported',
            'label_ids' => [$this->archived->id],
        ])->assertSuccessful()->json('data.id') ?? Transaction::query()->latest()->value('id');

        expect(Transaction::query()->findOrFail($id)->labels->pluck('id')->all())->toBe([$this->archived->id]);
        expect(Label::query()->where('user_id', $this->user->id)->where('name', 'Old trip')->count())->toBe(1);
    });

    it('lets an edit keep an archived label the transaction already has, and remove it', function () {
        $transaction = archivedLabelsTransaction(labels: [$this->archived]);

        actingAs($this->user)->patchJson(route('transactions.update', $transaction), [
            'label_ids' => [$this->archived->id, $this->active->id],
        ])->assertSuccessful();

        expect($transaction->fresh()->labels->pluck('id')->sort()->values()->all())
            ->toBe(collect([$this->archived->id, $this->active->id])->sort()->values()->all());

        actingAs($this->user)->patchJson(route('transactions.update', $transaction), [
            'label_ids' => [$this->active->id],
        ])->assertSuccessful();

        expect($transaction->fresh()->labels->pluck('id')->all())->toBe([$this->active->id]);
    });

    it('refuses an edit that adds an archived label', function () {
        $transaction = archivedLabelsTransaction(labels: [$this->active]);

        actingAs($this->user)->patchJson(route('transactions.update', $transaction), [
            'label_ids' => [$this->active->id, $this->archived->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['label_ids.1' => 'Unarchive it first']);

        expect($transaction->fresh()->labels->pluck('id')->all())->toBe([$this->active->id]);
    });

    it('refuses a bulk edit that would add an archived label to a row without it', function () {
        $with = archivedLabelsTransaction(labels: [$this->archived]);
        $without = archivedLabelsTransaction();

        actingAs($this->user)->patchJson(route('transactions.bulk-update'), [
            'transaction_ids' => [$with->id, $without->id],
            'label_ids' => [$this->archived->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['label_ids.0']);

        expect($without->fresh()->labels)->toBeEmpty();
    });

    it('lets a bulk edit keep an archived label every selected row already has', function () {
        $first = archivedLabelsTransaction(labels: [$this->archived]);
        $second = archivedLabelsTransaction(labels: [$this->archived]);

        actingAs($this->user)->patchJson(route('transactions.bulk-update'), [
            'transaction_ids' => [$first->id, $second->id],
            'label_ids' => [$this->archived->id, $this->active->id],
        ])->assertOk();

        expect($second->fresh()->labels)->toHaveCount(2);
    });

    it('applies the same check to a bulk edit over the filtered rows', function () {
        archivedLabelsTransaction(labels: [$this->archived]);
        archivedLabelsTransaction();

        actingAs($this->user)->patchJson(route('transactions.bulk-update'), [
            'filters' => ['search' => ''],
            'label_ids' => [$this->archived->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['label_ids.0']);
    });

    it('lets split parts keep the original\'s archived label but refuses other archived ones', function () {
        $original = archivedLabelsTransaction(['amount' => -3000], [$this->archived]);
        $otherArchived = Label::factory()->archived()->create(['user_id' => $this->user->id]);

        actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
            'splits' => [
                ['amount' => -1000, 'label_ids' => [$otherArchived->id]],
                ['amount' => -2000],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['splits.0.label_ids' => 'is archived']);

        actingAs($this->user)->postJson("/transactions/{$original->id}/split", [
            'splits' => [
                ['amount' => -1000, 'label_ids' => [$this->archived->id]],
                ['amount' => -2000, 'label_ids' => [$this->active->id]],
            ],
        ])->assertSuccessful();

        expect($original->splits()->with('labels')->get()->flatMap->labels->pluck('id')->all())
            ->toContain($this->archived->id);
    });
});

describe('assigning to transactions through the API', function () {
    it('refuses an archived label on a new transaction', function () {
        withHeaders(archivedLabelsApi())->postJson('/api/v1/transactions', [
            'account_id' => $this->account->id,
            'description' => 'Hotel',
            'amount' => -5000,
            'transaction_date' => '2026-11-10',
            'label_ids' => [$this->archived->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['label_ids' => 'is archived']);

        expect(Transaction::query()->where('description', 'Hotel')->exists())->toBeFalse();
    });

    it('replaces labels keeping an archived one already there, but refuses adding one', function () {
        $transaction = archivedLabelsTransaction(labels: [$this->archived]);
        $otherArchived = Label::factory()->archived()->create(['user_id' => $this->user->id]);

        withHeaders(archivedLabelsApi())
            ->putJson("/api/v1/transactions/{$transaction->id}/labels", ['label_ids' => [$this->archived->id, $this->active->id]])
            ->assertOk();

        withHeaders(archivedLabelsApi())
            ->putJson("/api/v1/transactions/{$transaction->id}/labels", ['label_ids' => [$otherArchived->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['label_ids' => 'is archived']);

        expect($transaction->fresh()->labels)->toHaveCount(2);
    });

    it('refuses an archived label on a split part unless the original carries it', function () {
        $original = archivedLabelsTransaction(['amount' => -3000]);

        withHeaders(archivedLabelsApi())->postJson("/api/v1/transactions/{$original->id}/split", [
            'splits' => [
                ['amount' => -1000, 'label_ids' => [$this->archived->id]],
                ['amount' => -2000],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['splits.0.label_ids']);
    });
});

describe('assigning to transactions through the MCP', function () {
    it('refuses to add an archived label, keeps one already there and removes it', function () {
        $transaction = archivedLabelsTransaction();

        archivedLabelsTool(LabelTransaction::class, [
            'transaction_id' => $transaction->id,
            'add_label_ids' => [$this->archived->id],
        ])->assertHasErrors(['is archived']);

        expect($transaction->fresh()->labels)->toBeEmpty();

        $transaction->labels()->attach($this->archived->id);

        archivedLabelsTool(LabelTransaction::class, [
            'transaction_id' => $transaction->id,
            'add_label_ids' => [$this->archived->id],
        ])->assertOk();

        archivedLabelsTool(LabelTransaction::class, [
            'transaction_id' => $transaction->id,
            'remove_label_ids' => [$this->archived->id],
        ])->assertOk();

        expect($transaction->fresh()->labels)->toBeEmpty();
    });

    it('refuses an archived label on create_transaction', function () {
        archivedLabelsTool(CreateTransaction::class, [
            'account_id' => $this->account->id,
            'description' => 'Hotel',
            'amount' => -5000,
            'transaction_date' => '2026-11-10',
            'label_ids' => [$this->archived->id],
        ])->assertHasErrors(['is archived']);

        expect(Transaction::query()->where('description', 'Hotel')->exists())->toBeFalse();
    });

    it('refuses an archived label on split_transaction unless the original carries it', function () {
        $original = archivedLabelsTransaction(['amount' => -3000]);

        archivedLabelsTool(SplitTransaction::class, [
            'transaction_id' => $original->id,
            'splits' => [
                ['amount' => -1000, 'label_ids' => [$this->archived->id]],
                ['amount' => -2000],
            ],
        ])->assertHasErrors(['is archived']);

        $original->labels()->attach($this->archived->id);

        archivedLabelsTool(SplitTransaction::class, [
            'transaction_id' => $original->id,
            'splits' => [
                ['amount' => -1000, 'label_ids' => [$this->archived->id]],
                ['amount' => -2000],
            ],
        ])->assertOk();
    });
});

describe('automation rules', function () {
    it('skips archived labels when applied, and still applies the rest', function () {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
        $rule = AutomationRule::factory()->create([
            'user_id' => $this->user->id,
            'action_category_id' => $category->id,
        ]);
        $rule->labels()->attach([$this->archived->id, $this->active->id]);
        $transaction = archivedLabelsTransaction();

        app(AutomationRuleService::class)->applyRuleActionsToTransactions(Transaction::query()->whereKey($transaction->id)->get(), $rule->fresh());

        $transaction->refresh();
        expect($transaction->category_id)->toBe($category->id)
            ->and($transaction->labels->pluck('id')->all())->toBe([$this->active->id]);
    });

    it('skips archived labels when a new transaction runs the rules', function () {
        $rule = AutomationRule::factory()->create([
            'user_id' => $this->user->id,
            'rules_json' => ['in' => ['hotel', ['var' => 'description']]],
            'action_category_id' => null,
        ]);
        $rule->labels()->attach([$this->archived->id, $this->active->id]);
        $transaction = archivedLabelsTransaction(['description' => 'Hotel Ritz']);

        app(AutomationRuleService::class)->applyRules($transaction->fresh());

        expect($transaction->fresh()->labels->pluck('id')->all())->toBe([$this->active->id]);
    });

    it('treats a label-only rule whose labels are all archived as having nothing to add', function () {
        $rule = AutomationRule::factory()->create(['user_id' => $this->user->id, 'action_category_id' => null]);
        $rule->labels()->attach($this->archived->id);

        expect(app(AutomationRuleService::class)->shouldSkipForOnlyUncategorized($rule->load('labels'), archivedLabelsTransaction()->load('labels')))->toBeTrue();
    });

    it('refuses an archived label on a new rule from settings, but lets an edit keep it', function () {
        actingAs($this->user)->post(route('automation-rules.store'), [
            'title' => 'Trips',
            'priority' => 1,
            'rules_json' => json_encode(['in' => ['hotel', ['var' => 'description']]]),
            'action_label_ids' => [$this->archived->id],
        ])->assertSessionHasErrors('action_label_ids.0');

        $rule = AutomationRule::factory()->create(['user_id' => $this->user->id, 'action_category_id' => null]);
        $rule->labels()->attach($this->archived->id);

        actingAs($this->user)->patch(route('automation-rules.update', $rule), [
            'title' => 'Trips',
            'priority' => 1,
            'rules_json' => json_encode(['in' => ['hotel', ['var' => 'description']]]),
            'action_label_ids' => [$this->archived->id, $this->active->id],
        ])->assertSessionHasNoErrors();

        expect($rule->fresh()->labels)->toHaveCount(2);
    });

    it('refuses an archived label on a new rule through the API', function () {
        withHeaders(archivedLabelsApi())->postJson('/api/v1/automation-rules', [
            'title' => 'Trips',
            'priority' => 1,
            'conditions' => ['in' => ['hotel', ['var' => 'description']]],
            'action_label_ids' => [$this->archived->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['action_label_ids' => 'is archived']);
    });

    it('refuses to add an archived label to a rule through the MCP, but keeps one it has', function () {
        archivedLabelsTool(CreateAutomationRule::class, [
            'title' => 'Trips',
            'priority' => 1,
            'rules_json' => ['in' => ['hotel', ['var' => 'description']]],
            'action_label_ids' => [$this->archived->id],
        ])->assertHasErrors(['is archived']);

        $rule = AutomationRule::factory()->create(['user_id' => $this->user->id, 'action_category_id' => null]);
        $rule->labels()->attach($this->active->id);

        archivedLabelsTool(UpdateAutomationRule::class, [
            'automation_rule_id' => $rule->id,
            'action_label_ids' => [$this->active->id, $this->archived->id],
        ])->assertHasErrors(['is archived']);

        $rule->labels()->attach($this->archived->id);

        archivedLabelsTool(UpdateAutomationRule::class, [
            'automation_rule_id' => $rule->id,
            'action_label_ids' => [$this->archived->id],
        ])->assertOk();

        expect($rule->fresh()->labels->pluck('id')->all())->toBe([$this->archived->id]);
    });
});

describe('budgets', function () {
    it('refuses an archived label on a new budget on the web', function () {
        actingAs($this->user)->post('/budgets', [
            'name' => 'Trips',
            'period_type' => 'monthly',
            'period_start_day' => 1,
            'label_ids' => [$this->archived->id],
            'rollover_type' => 'reset',
            'allocated_amount' => 100000,
        ])->assertSessionHasErrors('label_ids');

        expect($this->user->budgets()->count())->toBe(0);
    });

    it('refuses an archived label on a new budget through the API and the MCP', function () {
        withHeaders(archivedLabelsApi())->postJson('/api/v1/budgets', [
            'name' => 'Trips',
            'allocated_amount' => 50_000,
            'period_type' => 'monthly',
            'rollover_type' => 'reset',
            'label_ids' => [$this->archived->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['label_ids' => 'is archived']);

        archivedLabelsTool(CreateBudget::class, [
            'name' => 'Trips',
            'allocated_amount' => 50_000,
            'period_type' => 'monthly',
            'rollover_type' => 'reset',
            'label_ids' => [$this->archived->id],
        ])->assertHasErrors(['is archived']);

        expect($this->user->budgets()->count())->toBe(0);
    });
});

describe('filters, search and reports', function () {
    it('filters the transactions page by an archived label', function () {
        archivedLabelsTransaction(labels: [$this->archived]);
        archivedLabelsTransaction();

        actingAs($this->user)->get(route('transactions.index', ['label_ids' => $this->archived->id]))
            ->assertInertia(fn ($page) => $page->has('transactions.data', 1));
    });

    it('saves a filter on an archived label', function () {
        actingAs($this->user)->postJson('/api/saved-filters', [
            'name' => 'Old trips',
            'filters' => ['label_ids' => [$this->archived->id]],
        ])->assertCreated();
    });

    it('searches by an archived label through the MCP', function () {
        archivedLabelsTransaction(['description' => 'Hotel Ritz'], [$this->archived]);
        archivedLabelsTransaction(['description' => 'Coffee']);

        archivedLabelsTool(SearchTransactions::class, ['label_ids' => [$this->archived->id]])
            ->assertOk()
            ->assertSee('Hotel Ritz')
            ->assertDontSee('Coffee');
    });

    it('filters API transaction searches by an archived label', function () {
        archivedLabelsTransaction(['description' => 'Hotel Ritz'], [$this->archived]);
        archivedLabelsTransaction(['description' => 'Coffee']);

        withHeaders(archivedLabelsApi())->getJson('/api/v1/transactions?label_ids[]='.$this->archived->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.description', 'Hotel Ritz');
    });

    it('reports spending under an archived label', function () {
        $category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
        archivedLabelsTransaction(['category_id' => $category->id, 'transaction_date' => '2026-10-03', 'amount' => -2500], [$this->archived]);

        archivedLabelsTool(SpendingByLabel::class, ['from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertOk()
            ->assertSee('"label_id":"'.$this->archived->id.'"', false);
    });

    it('keeps archived labels in the dashboard top labels', function () {
        archivedLabelsTransaction(['transaction_date' => now(), 'amount' => -2500], [$this->archived]);

        $labels = actingAs($this->user)->get(route('dashboard'), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'dashboard',
            'X-Inertia-Partial-Data' => 'topLabels',
        ])->assertOk()->json('props.topLabels');

        expect(collect($labels)->pluck('id')->all())->toContain($this->archived->id);
    });
});

describe('name uniqueness', function () {
    it('points at unarchiving when a new label clashes with an archived one on the web', function () {
        actingAs($this->user)->post(route('labels.store'), ['name' => 'Old trip', 'color' => 'blue'])
            ->assertSessionHasErrors(['name' => __('There is an archived label called “:name”. Unarchive it instead of creating another one.', ['name' => 'Old trip'])]);

        actingAs($this->user)->patch(route('labels.update', $this->active), ['name' => 'Old trip'])
            ->assertSessionHasErrors('name');
    });

    it('keeps the plain message when the clash is with an active label', function () {
        actingAs($this->user)->post(route('labels.store'), ['name' => 'Groceries', 'color' => 'blue'])
            ->assertSessionHasErrors(['name' => 'The name has already been taken.']);
    });

    it('translates the archived clash message', function () {
        app()->setLocale('es');

        expect(__('There is an archived label called “:name”. Unarchive it instead of creating another one.', ['name' => 'X']))
            ->toBe('Ya tienes una etiqueta archivada llamada «X». Desarchívala en lugar de crear otra.')
            ->and(__('The label “:name” is archived, so it cannot be added. Unarchive it first to use it again.', ['name' => 'X']))
            ->toBe('La etiqueta «X» está archivada y no se puede añadir. Desarchívala para volver a usarla.');
    });

    it('points at unarchiving through the API and the MCP', function () {
        withHeaders(archivedLabelsApi())->postJson('/api/v1/labels', ['name' => 'Old trip', 'color' => 'blue'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Unarchive it instead']);

        archivedLabelsTool(CreateLabel::class, ['name' => 'Old trip', 'color' => 'blue'])
            ->assertHasErrors(['Unarchive it instead']);
    });
});

describe('API and MCP label surface', function () {
    it('exposes archived and archived_at on API labels and archives through the update', function () {
        withHeaders(archivedLabelsApi())->getJson("/api/v1/labels/{$this->archived->id}")
            ->assertOk()
            ->assertJsonPath('data.archived', true)
            ->assertJsonPath('data.archived_at', $this->archived->archived_at->toIso8601String());

        withHeaders(archivedLabelsApi())->patchJson("/api/v1/labels/{$this->active->id}", ['archived' => true])
            ->assertOk()
            ->assertJsonPath('data.archived', true);

        withHeaders(archivedLabelsApi())->patchJson("/api/v1/labels/{$this->archived->id}", ['archived' => false])
            ->assertOk()
            ->assertJsonPath('data.archived', false)
            ->assertJsonPath('data.archived_at', null);

        expect($this->active->fresh()->isArchived())->toBeTrue()
            ->and($this->archived->fresh()->isArchived())->toBeFalse();
    });

    it('refuses archiving a savings goal label through the API and the MCP', function () {
        $goalLabel = Label::factory()->create(['user_id' => $this->user->id, 'source' => LabelSource::SavingsGoal]);

        withHeaders(archivedLabelsApi())->patchJson("/api/v1/labels/{$goalLabel->id}", ['archived' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['archived']);

        archivedLabelsTool(UpdateLabel::class, ['label_id' => $goalLabel->id, 'archived' => true])
            ->assertHasErrors(['cannot be archived']);

        expect($goalLabel->fresh()->archived_at)->toBeNull();
    });

    it('archives and unarchives through update_label', function () {
        archivedLabelsTool(UpdateLabel::class, ['label_id' => $this->active->id, 'archived' => true])
            ->assertOk()
            ->assertSee('"archived":true', false);

        archivedLabelsTool(UpdateLabel::class, ['label_id' => $this->active->id, 'archived' => false])
            ->assertOk()
            ->assertSee('"archived":false', false);

        expect($this->active->fresh()->archived_at)->toBeNull();
    });

    it('lists archived labels with the flag in list_labels', function () {
        archivedLabelsTool(ListLabels::class, [])
            ->assertOk()
            ->assertSee('"name":"Old trip","color":"'.$this->archived->color.'","archived":true', false)
            ->assertSee('"name":"Groceries","color":"'.$this->active->color.'","archived":false', false);
    });
});
