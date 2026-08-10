<?php

namespace App\Mcp\Tools;

use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

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
    protected function respond(Request $request, User $user): Response
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

    abstract protected function write(Request $request, User $user): Response;

    /**
     * Resolve an account in the space. Bank-connected accounts are allowed:
     * a sync only inserts rows it has not seen before, so a manual transaction
     * added to a connected account survives every later sync.
     */
    protected function accountInSpace(Request $request, Space $space, string $key = 'account_id'): Account
    {
        $id = $request->string($key)->toString();

        $account = Account::query()->forSpace($space)->whereKey($id)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                $key => "No account with id {$id} in space {$space->id}. Call list_accounts to see valid ids.",
            ]);
        }

        return $account;
    }

    /**
     * Resolve an account whose balance snapshots may be written. Connected
     * accounts are rejected: their balances come from the bank and any manual
     * snapshot would be overwritten by the next sync.
     */
    protected function balanceWritableAccount(Request $request, Space $space, string $key = 'account_id'): Account
    {
        $account = $this->accountInSpace($request, $space, $key);

        if ($account->isConnected()) {
            throw ValidationException::withMessages([
                $key => 'That account is connected to a bank, so its balances come from the sync and a manual snapshot would be overwritten. Only non-connected (manual) accounts accept balances.',
            ]);
        }

        return $account;
    }

    protected function transactionInSpace(Request $request, Space $space, string $key = 'transaction_id'): Transaction
    {
        $id = $request->string($key)->toString();

        $transaction = Transaction::query()->forSpace($space)->whereKey($id)->first();

        if ($transaction === null) {
            throw ValidationException::withMessages([
                $key => "No transaction with id {$id} in space {$space->id}. Call search_transactions to find ids.",
            ]);
        }

        return $transaction;
    }

    protected function categoryInSpace(Request $request, Space $space, string $key = 'category_id'): Category
    {
        $id = $request->string($key)->toString();

        $category = Category::query()->forSpace($space)->whereKey($id)->first();

        if ($category === null) {
            throw ValidationException::withMessages([
                $key => "No category with id {$id} in space {$space->id}. Call list_categories to see valid ids.",
            ]);
        }

        return $category;
    }

    protected function labelInSpace(Request $request, Space $space, string $key = 'label_id'): Label
    {
        $id = $request->string($key)->toString();

        $label = Label::query()->forSpace($space)->whereKey($id)->first();

        if ($label === null) {
            throw ValidationException::withMessages([
                $key => "No label with id {$id} in space {$space->id}. Call list_labels to see valid ids.",
            ]);
        }

        return $label;
    }

    /**
     * The transaction shape returned by every transaction write tool, matching
     * the fields search_transactions exposes so the agent sees a familiar row.
     *
     * @return array<string, mixed>
     */
    protected function presentTransaction(Transaction $transaction): array
    {
        $transaction->loadMissing(['account:id,name', 'category:id,name', 'labels:id,name']);

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
            'labels' => $transaction->labels
                ->map(fn (Label $label): array => ['id' => $label->id, 'name' => $label->name])
                ->values()
                ->all(),
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

    /**
     * @return array<string, mixed>
     */
    protected function presentAutomationRule(AutomationRule $rule): array
    {
        $rule->loadMissing('labels:id,name');

        return [
            'id' => $rule->id,
            'title' => $rule->title,
            'priority' => $rule->priority,
            'rules_json' => $rule->rules_json,
            'action_category_id' => $rule->action_category_id,
            'action_note' => $rule->action_note,
            'origin' => $rule->origin->value,
            'labels' => $rule->labels
                ->map(fn (Label $label): array => ['id' => $label->id, 'name' => $label->name])
                ->values()
                ->all(),
        ];
    }
}
