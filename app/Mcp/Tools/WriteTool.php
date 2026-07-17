<?php

namespace App\Mcp\Tools;

use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Base for every Whisper Money write tool. On top of the McpTool Pro-plan gate
 * it requires the calling token to carry the `mcp:write` ability, so a
 * read-only token can analyse data but never change it.
 *
 * Each concrete write tool must additionally carry the #[IsDestructive]
 * annotation. PHP attributes are not inherited, so the framework only reports
 * one declared directly on the served tool class — it cannot live here.
 */
abstract class WriteTool extends McpTool
{
    protected function respond(Request $request, User $user): Response
    {
        if (! $user->tokenCan('mcp:write')) {
            return Response::error('This token is read-only. Create a read & write token to make changes.');
        }

        return $this->write($request, $user);
    }

    abstract protected function write(Request $request, User $user): Response;

    /**
     * Resolve an account the token may write to: it must live in the space and
     * must not be connected to a bank (bank-sourced data is never touched).
     */
    protected function writableAccount(Request $request, Space $space, string $key = 'account_id'): Account
    {
        $id = $request->string($key)->toString();

        $account = Account::query()->forSpace($space)->whereKey($id)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                $key => "No account with id {$id} in space {$space->id}. Call list_accounts to see valid ids.",
            ]);
        }

        if ($account->isConnected()) {
            throw ValidationException::withMessages([
                $key => 'That account is connected to a bank and is read-only. Only non-connected (manual) accounts can be written to.',
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
     * Resolve every label id passed under $key, asserting each belongs to the
     * space. Returns an empty collection when the argument is absent or empty.
     *
     * @return Collection<int, Label>
     */
    protected function labelsInSpace(Request $request, Space $space, string $key): Collection
    {
        $ids = collect($request->get($key, []))
            ->map(fn (mixed $id): string => (string) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            /** @var Collection<int, Label> $empty */
            $empty = Label::query()->whereRaw('1 = 0')->get();

            return $empty;
        }

        $labels = Label::query()->forSpace($space)->whereIn('id', $ids)->get();

        if ($labels->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                $key => "One or more label ids do not exist in space {$space->id}. Call list_labels to see valid ids.",
            ]);
        }

        return $labels;
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
