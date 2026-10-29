<?php

namespace App\Mcp\Tools;

use App\Enums\AccountType;
use App\Mcp\Tools\Concerns\WritesAccounts;
use App\Models\User;
use App\Services\Accounts\AccountWriteRules;
use App\Services\AccountWriteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Edit an account: name, type, currency, bank, ownership share and the loan/property details. Only the fields you pass change. On a bank-connected account only the name and ownership can change.')]
class UpdateAccount extends WriteTool
{
    use WritesAccounts;

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
            ...$this->sharedSchema($schema),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $rules = new AccountWriteRules($user);

        $space = $this->resolveSpace($request, $user);
        $account = $this->accountInSpace($request, $space);

        $request->validate($rules->typeRule(required: false));

        $requestedType = $request->has('type') ? $request->enum('type', AccountType::class) : null;
        $type = $requestedType ?? $account->type;

        AccountWriteRules::throwIfAny($rules->syncOwnedFieldErrors($account, $request->all(), $requestedType));

        $validated = $request->validate($rules->updateRules($type, $account->creditCardDetail));

        $missingLoanFields = app(AccountWriteService::class)->update($account, [
            ...$validated,
            ...$request->has('type') ? ['type' => $type->value] : [],
        ]);

        AccountWriteRules::throwIfAny(AccountWriteRules::missingLoanFieldErrors($missingLoanFields));

        $account->refresh();

        return $this->json(['account' => $this->presentAccount($account, $user)]);
    }
}
