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
#[Description('Create a label. Names are unique within the space.')]
class CreateLabel extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Label name (unique within the space).')->required(),
            'color' => $schema->string()->enum(array_column(LabelColor::cases(), 'value'))->description('Label color.')->required(),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'color' => ['required', Rule::enum(LabelColor::class)],
        ]);

        $space = $this->resolveSpace($request, $user);
        $name = $request->string('name')->toString();

        if (Label::query()->forSpace($space)->where('name', $name)->exists()) {
            throw ValidationException::withMessages([
                'name' => 'A label with that name already exists.',
            ]);
        }

        $label = new Label([
            'user_id' => $user->id,
            'space_id' => $space->id,
            'name' => $name,
            'color' => $request->string('color')->toString(),
        ]);
        $label->save();

        return $this->json(['label' => $this->presentLabel($label)]);
    }
}
