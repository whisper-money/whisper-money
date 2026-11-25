<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsAutomationRules;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounts\AccountBalanceRecorder;
use App\Services\Accounts\AccountWriteRules;
use App\Services\Labels\LabelWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * Base for every Whisper Money write tool. On top of the McpTool Pro-plan gate
 * it gates write access: OAuth connections (Claude Desktop / ChatGPT) get
 * read+write, and Sanctum personal access tokens must carry the `mcp:write`
 * ability, so a read-only PAT can analyse data but never change it.
 *
 * Only the irreversible tools (the deletes) carry #[IsDestructive]; creating,
 * updating, categorizing and labelling are reversible and inherit
 * `destructiveHint: false` from McpTool. PHP attributes are not inherited, so
 * the framework only reports one declared directly on the served tool class —
 * it cannot live here.
 */
abstract class WriteTool extends McpTool
{
    use PresentsAutomationRules;

    /**
     * Every write tool declares `openWorldHint: true`. OpenAI's reference would
     * allow false for a private account, but its MCP scan flags each write tool
     * that says so and holds the tool's updates until the finding is appealed,
     * and an appeal pauses every automatic MCP update. Declaring it keeps tool
     * changes shipping; ChatGPT asks before each write either way.
     *
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return [...parent::annotations(), 'openWorldHint' => true];
    }

    protected function respond(Request $request, User $user): Response|ResponseFactory
    {
        // Write access is granted to OAuth connections (Claude Desktop /
        // ChatGPT, resolved via the `api` guard — the user approves the
        // connection on the consent screen) and to Sanctum personal access
        // tokens carrying the mcp:write ability. A read-only Sanctum token is
        // rejected.
        if (Auth::getDefaultDriver() !== 'api' && ! $user->tokenCan('mcp:write')) {
            return Response::error('This token is read-only. Create a read & write token to make changes.');
        }

        return $this->write($request, $user);
    }

    abstract protected function write(Request $request, User $user): Response|ResponseFactory;

    /**
     * Assign only the fields the request actually carries — the "only what you
     * pass changes" contract every update tool promises. Values are closures so
     * that resolving a related model (which may fail validation) happens only
     * when the field is present.
     *
     * @param  array<string, callable(): mixed>  $fields
     */
    protected function applyFields(Request $request, Model $model, array $fields): void
    {
        foreach ($this->requestedFields($request, $fields) as $attribute => $value) {
            $model->{$attribute} = $value;
        }
    }

    /**
     * The values of the fields the request actually carries, resolved the way
     * applyFields() resolves them, for a shared writer to apply.
     *
     * @param  array<string, callable(): mixed>  $fields
     * @return array<string, mixed>
     */
    protected function requestedFields(Request $request, array $fields): array
    {
        $values = [];

        foreach ($fields as $attribute => $value) {
            if ($request->has($attribute)) {
                $values[$attribute] = $value();
            }
        }

        return $values;
    }

    /**
     * A string field, or null when the agent passed an empty value to clear it.
     */
    protected function nullableString(Request $request, string $key): ?string
    {
        return $request->filled($key) ? $request->string($key)->toString() : null;
    }

    /**
     * Resolve an account whose balance snapshots may be written. Connected
     * accounts are rejected: their balances come from the bank and any manual
     * snapshot would be overwritten by the next sync. Credit cards are
     * rejected too: they have a credit limit instead of a balance.
     */
    protected function balanceWritableAccount(Request $request, Space $space, string $key = 'account_id'): Account
    {
        $account = $this->accountInSpace($request, $space, $key);

        AccountWriteRules::throwIfAny(AccountBalanceRecorder::unwritableErrors($account, $key));

        return $account;
    }

    /**
     * Assert an already-resolved account accepts balance writes. Reached from
     * the other direction too: a tool given a snapshot's id resolves the account
     * from it and needs the same verdict, worded the same way.
     */
    protected function assertBalanceWritable(Account $account, string $key = 'account_id'): void
    {
        if ($account->isConnected()) {
            throw ValidationException::withMessages([$key => AccountBalanceRecorder::ConnectedAccountMessage]);
        }
    }

    protected function transactionInSpace(Request $request, Space $space, string $key = 'transaction_id'): Transaction
    {
        return $this->modelInSpace($request, $space, Transaction::query()->forSpace($space), $key, 'transaction', 'Call search_transactions to find ids.');
    }

    protected function categoryInSpace(Request $request, Space $space, string $key = 'category_id'): Category
    {
        return $this->modelInSpace($request, $space, Category::query()->forSpace($space), $key, 'category', 'Call list_categories to see valid ids.');
    }

    protected function labelInSpace(Request $request, Space $space, string $key = 'label_id'): Label
    {
        return $this->modelInSpace($request, $space, Label::query()->forSpace($space), $key, 'label', 'Call list_labels to see valid ids.');
    }

    protected function ruleInSpace(Request $request, Space $space, string $key = 'automation_rule_id'): AutomationRule
    {
        return $this->modelInSpace($request, $space, AutomationRule::query()->forSpace($space), $key, 'automation rule');
    }

    /**
     * Refuse renaming or deleting the label of a savings goal, as the API
     * does (LabelWriter::savingsGoalLabelErrors()).
     *
     * @param  list<array-key>  $sent  the fields the write carries
     */
    protected function refuseSavingsGoalLabelChange(Label $label, array $sent, bool $deleting): void
    {
        $errors = LabelWriter::savingsGoalLabelErrors($label, $sent, $deleting);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Refuse a label name already used in the space, whichever member's it is,
     * optionally ignoring one label by id (for edits).
     */
    protected function refuseTakenLabelName(Space $space, string $name, ?string $ignoreId = null): void
    {
        if (app(LabelWriter::class)->nameTaken($space, $name, $ignoreId)) {
            throw ValidationException::withMessages(['name' => LabelWriter::NameTakenMessage]);
        }
    }

    /**
     * Resolve a budget. Budgets hang off the user rather than off a space,
     * matching how the app decides which budgets a transaction feeds.
     *
     * An archived budget is read-only for good, so it is refused here rather
     * than in each tool. Deleting one is still fine, which is what
     * $allowArchived is for.
     */
    protected function budgetOfUser(Request $request, User $user, string $key = 'budget_id', bool $allowArchived = false): Budget
    {
        $id = $request->string($key)->toString();

        $budget = $user->budgets()->whereKey($id)->first();

        if ($budget === null) {
            throw ValidationException::withMessages([
                $key => "No budget with id {$id}. Call list_budgets to see valid ids.",
            ]);
        }

        if ($budget->isArchived() && ! $allowArchived) {
            throw ValidationException::withMessages([
                $key => "Budget {$id} is archived and can no longer be changed. Archiving cannot be undone.",
            ]);
        }

        return $budget;
    }

    /**
     * The transaction shape returned by every transaction write tool, matching
     * the fields search_transactions exposes so the agent sees a familiar row.
     *
     * @return array<string, mixed>
     */
    protected function presentTransaction(Transaction $transaction): array
    {
        $transaction->loadMissing(['account:id,name', 'category:id,name', 'labels:id,name', 'splits.category:id,name']);

        return [
            'id' => $transaction->id,
            'date' => $transaction->transaction_date->toDateString(),
            'description' => $transaction->description,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency_code,
            'category_id' => $transaction->category_id,
            'category' => $transaction->category?->name,
            'category_source' => $transaction->category_source?->value,
            'account_id' => $transaction->account_id,
            'account' => $transaction->account?->name,
            'source' => $transaction->source->value,
            'creditor_name' => $transaction->creditor_name,
            'debtor_name' => $transaction->debtor_name,
            // Plain text, and editable on any transaction through
            // update_transaction. Rows the old client-side encryption never
            // migrated still hold ciphertext here; they are returned as they
            // are, because only the browser holds the key.
            'notes' => $transaction->notes,
            'labels' => $transaction->labels
                ->map(fn (Label $label): array => ['id' => $label->id, 'name' => $label->name])
                ->values()
                ->all(),
            // Set on one part of a split, shared by its siblings: pass any of
            // them to merge_transaction_splits.
            'split_parent_id' => $transaction->split_parent_id,
            // The old split lines, on a transaction not yet converted to parts.
            'is_split' => $transaction->splits->isNotEmpty(),
            'splits' => $transaction->splits->map(fn ($split): array => [
                'id' => $split->id,
                'category_id' => $split->category_id,
                'category' => $split->category?->name,
                'amount' => $split->amount,
                'position' => $split->position,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentCategory(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'icon' => $category->icon,
            'color' => $category->color,
            'type' => $category->type->value,
            'cashflow_direction' => $category->cashflow_direction->value,
            'parent_id' => $category->parent_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentLabel(Label $label): array
    {
        return [
            'id' => $label->id,
            'name' => $label->name,
            'color' => $label->color,
        ];
    }
}
