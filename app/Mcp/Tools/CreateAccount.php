<?php

namespace App\Mcp\Tools;

use App\Enums\AccountType;
use App\Mcp\Tools\Concerns\PresentsAccounts;
use App\Mcp\Tools\Concerns\ValidatesAccountWrites;
use App\Models\User;
use App\Services\AccountWriteService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a manual account of any type, optionally with today\'s balance. Loans and properties take their own details. Bank-connected accounts cannot be created here: the user connects those in the app.')]
class CreateAccount extends WriteTool
{
    use PresentsAccounts, ValidatesAccountWrites;

    /**
     * Fields that would point the new account at a bank connection. Only the
     * provider consent flow in the app may do that, so an agent reaching for
     * one is told where the real thing lives instead of getting an account that
     * looks connected and never syncs.
     *
     * @var list<string>
     */
    private const CONNECTION_FIELDS = ['banking_connection_id', 'external_account_id', 'is_connected'];

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
            'balance' => $schema->integer()->description('Optional balance today, in the currency\'s minor units. On a loan or a property it is also what the generated balance history ends at.'),
            'linked_real_estate_account_id' => $schema->string()->description('loan only: id of the real_estate account this loan is the mortgage of. The property must not already be linked to a loan.'),
            ...$this->detailSchema($schema),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $this->refuseConnectedAccount($request);
        $this->buildRulesFor($user);

        $type = $this->validatedType($request);

        $validated = $request->validate([
            'name' => ['required', 'string'],
            'bank_id' => ['nullable', 'exists:banks,id'],
            'currency_code' => ['required', 'string', Rule::in($this->allowedCurrencyCodes($user))],
            'balance' => ['nullable', 'integer'],
            ...$this->detailRules($type, creating: true),
        ]);

        $space = $this->resolveSpace($request, $user);

        $account = app(AccountWriteService::class)->create(
            $user,
            [...$validated, 'type' => $type->value],
            $space->id,
        );

        return $this->json(['account' => $this->presentAccount($account)]);
    }

    /**
     * The type has to be resolved before the rest of the rules, because which
     * detail fields are even accepted depends on it.
     */
    private function validatedType(Request $request): AccountType
    {
        $request->validate($this->typeRule(required: true));

        return $request->enum('type', AccountType::class);
    }

    private function refuseConnectedAccount(Request $request): void
    {
        foreach (self::CONNECTION_FIELDS as $field) {
            if (! $request->has($field)) {
                continue;
            }

            throw ValidationException::withMessages([
                $field => 'A bank-connected account cannot be created from here: it is born from the bank\'s consent flow, which only the user can go through in the Whisper Money app. Create a manual account instead, or ask the user to connect the bank themselves.',
            ]);
        }
    }
}
