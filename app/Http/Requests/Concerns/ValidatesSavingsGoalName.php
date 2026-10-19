<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * A savings goal's name, shared by the create and edit forms and the MCP
 * tools. The name is also its label's, so it must not clash with any live
 * label of the user's.
 */
trait ValidatesSavingsGoalName
{
    /**
     * @param  string|null  $ownLabelId  the goal's own label, when editing it
     * @return list<mixed>
     */
    protected function savingsGoalNameRules(string $userId, bool $creating, ?string $ownLabelId = null): array
    {
        return [
            ...($creating ? [] : ['sometimes']),
            'required',
            'string',
            'max:255',
            Rule::unique('labels', 'name')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->ignore($ownLabelId),
        ];
    }
}
