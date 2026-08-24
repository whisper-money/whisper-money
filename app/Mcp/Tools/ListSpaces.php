<?php

namespace App\Mcp\Tools;

use App\Models\Space;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the spaces the user can access (personal and shared). Pass a space id to the other tools\' `space` argument to query a specific one.')]
class ListSpaces extends McpTool
{
    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function respond(Request $request, User $user): Response
    {
        $spaces = $user->accessibleSpaces()
            ->map(fn (Space $space): array => [
                'id' => $space->id,
                'name' => $space->name,
                'personal' => $space->personal,
                'is_current' => $space->id === $user->current_space_id,
            ]);

        return $this->json(['spaces' => $spaces]);
    }
}
