<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsLabels;
use App\Models\Label;
use App\Models\User;
use App\Services\SpaceResolver;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the user\'s labels in a space. Use the ids with label_transaction or rule actions. Archived ones are listed and flagged: they work in filters but cannot be newly assigned.')]
class ListLabels extends McpTool
{
    use PresentsLabels;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'space' => $schema->string()->description('Space id to query. Defaults to the personal space.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);

        $labels = app(SpaceResolver::class)->ownedInSpace(Label::query(), $user, $space)
            ->orderBy('name')
            ->get()
            ->map(fn (Label $label): array => $this->presentLabel($label));

        return $this->json([
            'space_id' => $space->id,
            'labels' => $labels,
        ]);
    }
}
