<?php

namespace App\Mcp\Tools;

use App\Enums\CategorySource;
use App\Enums\TransactionSource;
use App\Models\User;
use App\Services\ManualBalanceAdjuster;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Edit a manually-created transaction; only the fields you pass change. Bank/imported ones keep their core fields locked — use categorize_transaction or label_transaction for those instead.')]
class UpdateTransaction extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->string()->description('Id of the manually-created transaction to edit.')->required(),
            'description' => $schema->string()->description('New description.'),
            'amount' => $schema->integer()->description('New signed amount, in the minor units of the transaction\'s own currency.'),
            'transaction_date' => $schema->string()->description('New transaction date, YYYY-MM-DD.'),
            'currency_code' => $schema->string()->description('New ISO 4217 currency code (3 letters).'),
            'account_id' => $schema->string()->description('Move the transaction to another account.'),
            'category_id' => $schema->string()->description('New category id, or null to clear the category.'),
            'creditor_name' => $schema->string()->description('New creditor (payee) name.'),
            'debtor_name' => $schema->string()->description('New debtor (payer) name.'),
            'notes' => $schema->string()->description('New free-text notes.'),
            'update_balance' => $schema->boolean()->description('When true and the amount/date/account changed, move the account balance snapshots accordingly. Ignored on connected accounts, whose balances come from the bank. Default false.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);
        $transaction = $this->transactionInSpace($request, $space);

        if ($transaction->source !== TransactionSource::ManuallyCreated) {
            return Response::error('Only manually-created transactions can be edited. This one came from a bank or import, so its core fields are locked. Use categorize_transaction or label_transaction instead.');
        }

        if ($transaction->isSplitPart()) {
            return Response::error('This transaction is one part of a split, so its amount, date and account are locked. Use categorize_transaction or label_transaction instead.');
        }

        $request->validate([
            'description' => ['sometimes', 'string'],
            'amount' => ['sometimes', 'integer'],
            'transaction_date' => ['sometimes', 'date'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'creditor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'debtor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Snapshot the pre-edit account/date/amount so a manual balance can be
        // moved off the old values if the edit changes them.
        $originalSnapshot = clone $transaction;

        $this->applyFields($request, $transaction, [
            'description' => fn () => $request->string('description')->toString(),
            'amount' => fn () => $request->integer('amount'),
            'transaction_date' => fn () => Carbon::parse($request->string('transaction_date')->toString()),
            'currency_code' => fn () => mb_strtoupper($request->string('currency_code')->toString()),
            'account_id' => fn () => $this->accountInSpace($request, $space)->id,
            'notes' => fn () => $this->nullableString($request, 'notes'),
            'creditor_name' => fn () => $this->nullableString($request, 'creditor_name'),
            'debtor_name' => fn () => $this->nullableString($request, 'debtor_name'),
        ]);

        // A new category is always a manual assignment: reset any AI/rule
        // provenance so the row is not later treated as machine-categorized.
        // ponytail: unlike the web edit path this does not learn a correction
        // rule — MCP writes stay predictable and side-effect free.
        if ($request->has('category_id')) {
            $newCategoryId = $request->filled('category_id') ? $this->categoryInSpace($request, $space)->id : null;

            if ($newCategoryId !== $transaction->category_id) {
                $transaction->category_id = $newCategoryId;
                $transaction->category_source = $newCategoryId === null ? null : CategorySource::Manual;
                $transaction->ai_confidence = null;
                $transaction->categorized_by_rule_id = null;
            }
        }

        $transaction->save();

        $balanceUpdated = false;

        if ($request->boolean('update_balance') && $transaction->wasChanged(ManualBalanceAdjuster::BALANCE_AFFECTING_ATTRIBUTES)) {
            $adjuster = app(ManualBalanceAdjuster::class);
            // Either side no-ops on a connected account, so moving a transaction
            // onto one still unwinds the manual account it came from.
            $reversed = $adjuster->reverseDeletedTransaction($originalSnapshot);
            $applied = $adjuster->applyCreatedTransaction($transaction->load('account'));
            $balanceUpdated = $reversed || $applied;
        }

        return $this->json([
            'transaction' => $this->presentTransaction($transaction->refresh()),
            'balance_updated' => $balanceUpdated,
        ]);
    }
}
