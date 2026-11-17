<?php

namespace App\Mcp\Tools;

use App\Enums\LabelColor;
use App\Models\User;
use App\Services\Labels\LabelWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Edit a label: rename, recolor, or archive/unarchive it. Only the fields you pass are changed. An archived label cannot be assigned to anything new.')]
class UpdateLabel extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'label_id' => $schema->string()->description('Id of the label to edit.')->required(),
            'name' => $schema->string()->description('New label name (unique within the space).'),
            'color' => $schema->string()->enum(array_column(LabelColor::cases(), 'value'))->description('New label color.'),
            'archived' => $schema->boolean()->description('true to archive the label, false to unarchive it. An archived label stays on the transactions, rules and budgets that already have it and still works in filters and reports, but cannot be added to transactions, rules or new budgets. A savings goal\'s label cannot be archived.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate(LabelWriter::updateRules());

        $space = $this->resolveSpace($request, $user);
        $label = $this->labelInSpace($request, $space);

        $this->refuseSavingsGoalLabelChange($label, array_keys($request->all()), deleting: false);

        $label = app(LabelWriter::class)->update($label, $space, $request->only(['name', 'color', 'archived']));

        return $this->json(['label' => $this->presentLabel($label)]);
    }
}
