<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

trait DecodesRulesJson
{
    /**
     * Decode and validate a JsonLogic condition argument, accepting either a
     * JSON object or a JSON-encoded string.
     *
     * @return array<string, mixed>
     */
    protected function rulesJson(Request $request, string $key = 'rules_json'): array
    {
        $rulesJson = $request->get($key);

        if (is_string($rulesJson)) {
            $rulesJson = json_decode($rulesJson, true);
        }

        if (! is_array($rulesJson) || $rulesJson === []) {
            throw ValidationException::withMessages([
                $key => "{$key} must be a non-empty JsonLogic object.",
            ]);
        }

        return $rulesJson;
    }
}
