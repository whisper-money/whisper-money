<?php

namespace App\Mcp\Tools;

use App\Features\Achievements;
use App\Models\User;
use App\Services\Achievements\Progress;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;

/**
 * Served even while the medals are switched off, unlike SplitTransaction, which
 * hides itself. A tool that vanishes leaves an agent with no way to say why, and
 * "not enabled for this account" is a better answer to "what medals do I have?"
 * than a catalog that quietly lacks the word.
 */
#[IsReadOnly]
#[Description('List the medals the user has earned and the ones still to come, grouped into tracks, with the unlocked count and the saving streak in progress. A locked medal comes back without a name.')]
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
        if (Feature::for($user)->inactive(Achievements::class)) {
            return Response::error('Achievements are not enabled for this account.');
        }

        // The progress screen's payload, handed over untouched: a locked medal
        // is a silhouette there, and naming it here would read out what the
        // screen deliberately keeps back.
        return $this->json(app(Progress::class)->for($user));
    }
}
