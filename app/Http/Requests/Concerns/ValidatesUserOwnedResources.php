<?php

namespace App\Http\Requests\Concerns;

use App\Enums\AccountType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

trait ValidatesUserOwnedResources
{
    /**
     * Rule asserting the value references a record on the given table owned by the authenticated user.
     */
    protected function userOwned(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('user_id', $this->user()->id);
    }

    /**
     * Rule asserting the value references an account of the given type owned by the authenticated user.
     */
    protected function userOwnedAccountOfType(AccountType $type): Exists
    {
        return $this->userOwned('accounts')->where('type', $type->value);
    }
}
