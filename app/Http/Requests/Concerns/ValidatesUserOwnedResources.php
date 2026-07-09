<?php

namespace App\Http\Requests\Concerns;

use App\Enums\AccountType;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

trait ValidatesUserOwnedResources
{
    /**
     * Rule asserting the value references a record on the given (space-owned)
     * table in the user's active space. Scoping by space rather than user lets a
     * member of a shared space reference that space's accounts, categories and
     * labels — the whole point of collaboration — while still blocking any row
     * outside the active space.
     */
    protected function userOwned(string $table): Exists
    {
        return Rule::exists($table, 'id')->where('space_id', $this->user()->activeSpace()->id);
    }

    /**
     * Rule asserting the value references an account of the given type in the
     * user's active space.
     */
    protected function userOwnedAccountOfType(AccountType $type): Exists
    {
        return $this->userOwned('accounts')->where('type', $type->value);
    }
}
