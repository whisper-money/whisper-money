<?php

namespace App\Mcp\Tools;

use App\Enums\LabelColor;
use App\Models\Label;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Edit a label. Only the fields you pass are changed.')]
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
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'color' => ['sometimes', Rule::enum(LabelColor::class)],
        ]);

        $space = $this->resolveSpace($request, $user);
        $label = $this->labelInSpace($request, $space);

        if ($request->has('name')) {
            $name = $request->string('name')->toString();

            $exists = Label::query()
                ->forSpace($space)
                ->where('name', $name)
                ->whereKeyNot($label->id)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'name' => 'A label with that name already exists.',
                ]);
            }

            $label->name = $name;
        }

        if ($request->has('color')) {
            $label->color = $request->string('color')->toString();
        }

        $label->save();

        return $this->json(['label' => $this->presentLabel($label)]);
    }
}
