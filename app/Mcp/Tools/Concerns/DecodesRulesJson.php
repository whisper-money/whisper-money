<?php

namespace App\Mcp\Tools\Concerns;

use App\Services\AutomationRules\RuleConditions;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

trait DecodesRulesJson
{
    /**
     * Decode and validate a JsonLogic condition argument, accepting either a
     * JSON object or a JSON-encoded string. What is accepted is
     * RuleConditions' to decide, as for `/api/v1/automation-rules`.
     *
     * @return array<string, mixed>
     */
    protected function rulesJson(Request $request, string $key = 'rules_json'): array
    {
        $rulesJson = $request->get($key);

        if (is_string($rulesJson)) {
            $rulesJson = json_decode($rulesJson, true);
        }

        $error = RuleConditions::error($rulesJson, $key);

        if ($error !== null) {
            throw ValidationException::withMessages([$key => $error]);
        }

        return $rulesJson;
    }

    /**
     * The shared `rules_json` schema description. Both write tools point at the
     * same text so an agent reads the same format whichever one it starts from.
     */
    protected function rulesJsonDescription(): string
    {
        return RuleConditions::Description;
    }
}
