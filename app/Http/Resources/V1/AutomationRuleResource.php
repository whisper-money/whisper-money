<?php

namespace App\Http\Resources\V1;

use App\Models\AutomationRule;
use App\Models\User;
use App\Services\AutomationRules\RuleConditions;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An automation rule: conditions a new transaction is checked against, and
 * the category, labels and note it gets when it meets them.
 *
 * @mixin AutomationRule
 */
#[SchemaName('AutomationRule')]
class AutomationRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $this->resource->loadMissing('labels:id');

        return [
            /**
             * The rule's id.
             *
             * @format uuid
             *
             * @example 0199a3f9-5d6e-7f8a-9b0c-1d2e3f4a5b6c
             */
            'id' => $this->id,
            /**
             * The id of the space the rule is in.
             *
             * @format uuid
             *
             * @example 0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60
             */
            'space_id' => $this->space_id,
            /**
             * The rule's name.
             *
             * @example Groceries
             */
            'title' => $this->title,
            /**
             * The order the rule is evaluated in, lowest first. A new transaction gets the actions of the first rule it matches, and no other.
             *
             * @example 10
             */
            'priority' => $this->priority,
            /**
             * The conditions a transaction must meet, as one JsonLogic object. Amounts in here are in MAJOR units, unlike every other amount in the API. A rule the AI categorization learned can still have none: `{}`.
             *
             * @var array<string, mixed>
             *
             * @example {"and": [{"in": ["mercadona", {"var": "description"}]}, {"<": [{"var": "amount"}, 0]}]}
             */
            'conditions' => RuleConditions::decoded($this->rules_json) ?: (object) [],
            /**
             * The id of the category the rule gives the matching transactions, or `null` for none.
             *
             * @format uuid
             *
             * @example 0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12
             */
            'action_category_id' => $this->action_category_id,
            /**
             * The ids of the labels the rule adds to the matching transactions.
             *
             * @var list<string>
             *
             * @example ["0199a3f6-3c4d-7e5f-9a6b-7c8d9e0f1a23"]
             */
            'action_label_ids' => $this->labels->pluck('id')->values()->all(),
            /**
             * The note the rule appends to the matching transactions' notes, or `null` for none.
             *
             * @example Weekly shop
             */
            'action_note' => $this->action_note,
            /**
             * Who made the rule: `user` for one made by hand (in the app, the AI connector or this API), `ai` for one Whisper Money's AI categorization learned, and `correction` for one it learned from your own recategorizations.
             *
             * @example user
             */
            'origin' => $this->origin->value,
            /**
             * Whether you created the rule. Every member of its space reads it, but only the one who created it can change, apply or delete it.
             */
            'owned' => $this->user_id === $user->id,
            /**
             * When the rule was created.
             *
             * @format date-time
             *
             * @example 2026-01-15T09:30:00+00:00
             */
            'created_at' => $this->created_at?->toIso8601String(),
            /**
             * When the rule was last changed.
             *
             * @format date-time
             *
             * @example 2026-01-20T18:05:00+00:00
             */
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
