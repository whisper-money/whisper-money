<?php

namespace App\Http\Resources\V1;

use App\Services\AutomationRules\RuleApplication;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What applying an automation rule to existing transactions did: a preview,
 * a run done right away or one queued for the background.
 *
 * @mixin RuleApplication
 */
#[SchemaName('RuleApplication')]
class RuleApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * `preview` when nothing was changed, `done` when the rule was applied to every match, `queued` when it is being applied in the background.
             *
             * @var 'preview'|'done'|'queued'
             *
             * @example preview
             */
            'status' => $this->status,
            /**
             * The rule's id.
             *
             * @format uuid
             *
             * @example 0199a3f9-5d6e-7f8a-9b0c-1d2e3f4a5b6c
             */
            'automation_rule_id' => $this->automationRuleId,
            /**
             * How many transactions the rule matches.
             *
             * @example 42
             */
            'total' => $this->total,
            /**
             * On a preview, the first 20 matches, newest first; empty otherwise.
             */
            'sample' => TransactionResource::collection($this->sample),
            /**
             * When `done`, how many matches the rule went over; `0` otherwise.
             *
             * @example 0
             */
            'applied' => $this->applied,
            /**
             * When `done`, how many of them it changed: one that already had the rule's category, labels and note is applied but not updated. `0` otherwise.
             *
             * @example 0
             */
            'updated' => $this->updated,
        ];
    }
}
