<?php

namespace App\Mcp\Tools;

use App\Enums\AccountType;
use App\Mcp\Tools\Concerns\PresentsAccounts;
use App\Mcp\Tools\Concerns\ValidatesAccountWrites;
use App\Models\Account;
use App\Models\User;
use App\Services\AccountWriteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Edit an account: name, type, currency, bank, ownership share and the loan/property details. Only the fields you pass change. On a bank-connected account only the name and ownership can change.')]
class UpdateAccount extends WriteTool
{
    use PresentsAccounts, ValidatesAccountWrites;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->description('Id of the account to edit.')->required(),
            'name' => $schema->string()->description('New account name. The only descriptive field a bank-connected account accepts.'),
            'type' => $schema->string()->enum(array_column(AccountType::cases(), 'value'))->description('New account type. On a connected account only checking, credit_card, savings and others are possible: the rest have no ledger for the sync to write into.'),
            'currency_code' => $schema->string()->description('New ISO currency code. Rejected on a connected account, where the currency comes from the bank.'),
            'bank_id' => $schema->string()->description('New bank id, or null to clear it. Rejected on a connected account, where the bank comes from the connection.'),
            'ownership_percentage' => $schema->integer()->description('The user\'s share of this account, 1-100. Below 100 the app counts only that slice in their figures, and past budgets are reweighed.'),
            'ownership_applies_to_balance' => $schema->boolean()->description('Whether the ownership share applies to the balance too, not just to transactions.'),
            ...$this->detailSchema($schema),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $this->buildRulesFor($user);

        $space = $this->resolveSpace($request, $user);
        $account = $this->accountInSpace($request, $space);

        // A space is shared, so list_accounts shows what a housemate owns too —
        // but an account belongs to its owner, not to the space.
        if ($account->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'account_id' => "Account {$account->id} belongs to another member of the space, so only its owner can edit it. You can still read it and add transactions to it.",
            ]);
        }

        $request->validate($this->typeRule(required: false));

        $requestedType = $request->has('type') ? $request->enum('type', AccountType::class) : null;
        $type = $requestedType ?? $account->type;

        $this->refuseSyncOwnedFields($request, $account, $requestedType);

        $validated = $request->validate([
            'name' => ['sometimes', 'string'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'currency_code' => ['sometimes', 'string', Rule::in($this->allowedCurrencyCodes($user))],
            'ownership_percentage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'ownership_applies_to_balance' => ['sometimes', 'boolean'],
            ...$this->detailRules($type, creating: false),
        ]);

        $missingLoanFields = app(AccountWriteService::class)->update($account, [
            ...$validated,
            ...$request->has('type') ? ['type' => $type->value] : [],
        ]);

        if ($missingLoanFields !== []) {
            throw ValidationException::withMessages(array_fill_keys(
                $missingLoanFields,
                'This account has no loan details yet, so annual_interest_rate, loan_term_months and original_amount are all needed to create them. The loan details were left alone; any other field in the same call was saved.',
            ));
        }

        $account->refresh();

        return $this->json(['account' => $this->presentAccount($account)]);
    }

    /**
     * The fields the bank sync owns on a connected account. The sync never
     * rewrites a name, so renaming one is safe and sticks — but its currency is
     * what its whole synced history is denominated in, its bank comes from the
     * connection, and a type with no transaction ledger leaves the sync nowhere
     * to write.
     *
     * Only a type the agent actually asked for is judged: an account that is
     * already of some other type stays editable in every other respect.
     */
    private function refuseSyncOwnedFields(Request $request, Account $account, ?AccountType $requestedType): void
    {
        if (! $account->isConnected()) {
            return;
        }

        if ($request->has('currency_code')) {
            throw ValidationException::withMessages([
                'currency_code' => 'That account is connected to a bank, so its currency came from the bank and every synced transaction is denominated in it. Changing it would reinterpret the whole history.',
            ]);
        }

        if ($request->has('bank_id')) {
            throw ValidationException::withMessages([
                'bank_id' => 'That account is connected to a bank, so its bank comes from the connection. To move it to another bank the user connects that bank in the Whisper Money app.',
            ]);
        }

        if ($requestedType !== null && ! $requestedType->canSyncBankTransactions()) {
            throw ValidationException::withMessages([
                'type' => "That account is connected to a bank, so its type has to keep a transaction ledger: only checking, credit_card, savings and others do. A balance-only type like {$requestedType->value} would leave the sync nowhere to write, and it would silently stop.",
            ]);
        }
    }
}
