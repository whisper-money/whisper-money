<?php

namespace App\Mcp\Tools;

use App\Models\Label;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the user\'s labels in a space. Use the ids with label_transaction or automation-rule label actions.')]
class ListLabels extends McpTool
{
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

        $labels = Label::query()
            ->forSpace($space)
            ->orderBy('name')
            ->get()
            ->map(fn (Label $label): array => [
                'id' => $label->id,
                'name' => $label->name,
                'color' => $label->color,
            ]);

        return $this->json([
            'space_id' => $space->id,
            'labels' => $labels,
        ]);
    }
}
