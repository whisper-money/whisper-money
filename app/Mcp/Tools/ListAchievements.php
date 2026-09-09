<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Achievements\Progress;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the user\'s medals: the ones earned, the next rung to aim at on each track with how far along they are, and the ones beyond it, which stay nameless. Plus the unlocked count and saving streak.')]
class ListAchievements extends McpTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function respond(Request $request, User $user): Response
    {
        // The progress screen's payload, handed over untouched, so what the
        // screen reveals and what an assistant can say stay the same thing: the
        // next rung named, everything past it a silhouette.
        return $this->json(app(Progress::class)->for($user));
    }
}
