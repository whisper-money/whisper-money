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
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Edit a manually-created transaction. Only manual transactions can be edited; bank/imported ones keep their core fields locked (use categorize_transaction or label_transaction for those). Only the fields you pass are changed.')]
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
            'amount' => $schema->integer()->description('New signed amount in minor units (cents).'),
            'transaction_date' => $schema->string()->description('New transaction date, YYYY-MM-DD.'),
            'currency_code' => $schema->string()->description('New ISO 4217 currency code (3 letters).'),
            'account_id' => $schema->string()->description('Move the transaction to another non-connected account.'),
            'category_id' => $schema->string()->description('New category id, or null to clear the category.'),
            'creditor_name' => $schema->string()->description('New creditor (payee) name.'),
            'debtor_name' => $schema->string()->description('New debtor (payer) name.'),
            'notes' => $schema->string()->description('New free-text notes.'),
            'update_balance' => $schema->boolean()->description('When true and the amount/date/account changed, move the manual account balance snapshots accordingly. Default false.'),
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

        if ($request->has('description')) {
            $transaction->description = $request->string('description')->toString();
        }
        if ($request->has('amount')) {
            $transaction->amount = $request->integer('amount');
        }
        if ($request->has('transaction_date')) {
            $transaction->transaction_date = Carbon::parse($request->string('transaction_date')->toString());
        }
        if ($request->has('currency_code')) {
            $transaction->currency_code = mb_strtoupper($request->string('currency_code')->toString());
        }
        if ($request->has('account_id')) {
            $transaction->account_id = $this->writableAccount($request, $space)->id;
        }
        if ($request->has('notes')) {
            $transaction->notes = $request->filled('notes') ? $request->string('notes')->toString() : null;
        }
        if ($request->has('creditor_name')) {
            $transaction->creditor_name = $request->filled('creditor_name') ? $request->string('creditor_name')->toString() : null;
        }
        if ($request->has('debtor_name')) {
            $transaction->debtor_name = $request->filled('debtor_name') ? $request->string('debtor_name')->toString() : null;
        }

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

        if ($request->boolean('update_balance') && $transaction->wasChanged(['amount', 'transaction_date', 'account_id'])) {
            $adjuster = app(ManualBalanceAdjuster::class);
            $adjuster->reverseDeletedTransaction($originalSnapshot);
            $adjuster->applyCreatedTransaction($transaction->load('account'));
        }

        return $this->json(['transaction' => $this->presentTransaction($transaction->refresh())]);
    }
}
