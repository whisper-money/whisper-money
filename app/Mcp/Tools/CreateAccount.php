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

#[Description('Create a manual account of any type, optionally with today\'s balance. Loans and properties take their own details. Bank-connected accounts cannot be created here: the user connects those in the app.')]
class CreateAccount extends WriteTool
{
    use WritesAccounts;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Account name, as the user would call it.')->required(),
            'type' => $schema->string()->enum(array_column(AccountType::cases(), 'value'))->description('Account type. checking, credit_card, savings and others keep a transaction ledger; investment, retirement, loan and real_estate track a balance only.')->required(),
            'currency_code' => $schema->string()->description('ISO currency code, e.g. EUR. The user\'s first account also sets the currency their whole account is reported in.')->required(),
            'bank_id' => $schema->string()->description('Optional id of the bank this account is held at, for its name and logo. It does not connect anything.'),
            'balance' => $schema->integer()->description('Optional balance today, in the currency\'s minor units. On a loan or a property it is also what the generated balance history ends at. Refused on a credit card, which takes a credit_limit instead.'),
            'linked_real_estate_account_id' => $schema->string()->description('loan only: id of the real_estate account this loan is the mortgage of. The property must not already be linked to a loan.'),
            ...$this->sharedSchema($schema),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $rules = new AccountWriteRules($user);
        AccountWriteRules::throwIfAny($rules->connectionFieldErrors($request->all()));

        // The type has to be resolved before the rest of the rules, because
        // which detail fields are even accepted depends on it.
        $request->validate($rules->typeRule(required: true));
        $type = $request->enum('type', AccountType::class);

        AccountWriteRules::throwIfAny($rules->creditCardBalanceErrors($request->all(), $type));

        $validated = $request->validate($rules->createRules($type));

        $space = $this->resolveSpace($request, $user);

        $account = app(AccountWriteService::class)->create(
            $user,
            [...$validated, 'type' => $type->value],
            $space->id,
        );

        return $this->json(['account' => $this->presentAccount($account, $user)]);
    }
}
