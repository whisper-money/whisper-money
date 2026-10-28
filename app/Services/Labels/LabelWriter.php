<?php

namespace App\Services\Labels;

use App\Enums\LabelColor;
use App\Enums\LabelSource;
use App\Models\Label;
use App\Models\Space;
use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creating and editing a label, shared by the label MCP tools and
 * `/api/v1/labels`, with the rules both validate with. A name is unique within
 * its space; a clash is a ValidationException under `name`.
 */
class LabelWriter
{
    private const string NameTakenMessage = 'A label with that name already exists.';

    /**
     * @return array<string, array<mixed>>
     */
    public static function createRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'color' => ['required', Rule::enum(LabelColor::class)],
        ];
    }

    /**
     * Every field is optional: only what is sent changes.
     *
     * @return array<string, array<mixed>>
     */
    public static function updateRules(): array
    {
        return array_map(
            fn (array $rules): array => ['sometimes', ...array_filter($rules, fn (mixed $rule): bool => ! in_array($rule, ['required', 'sometimes'], true))],
            self::createRules(),
        );
    }

    /**
     * Why the label cannot be renamed or deleted, keyed by the field at
     * fault, or nothing when it can. The label of a savings goal is how the
     * goal counts its contributions and carries the goal's name, so it is
     * managed through the goal; changing its color is fine.
     *
     * @param  list<string>  $sent  the fields the write carries
     * @return array<string, string>
     */
    public static function savingsGoalLabelErrors(Label $label, array $sent, bool $deleting): array
    {
        if ($label->source !== LabelSource::SavingsGoal) {
            return [];
        }

        if ($deleting) {
            return ['label' => 'This label tracks a savings goal\'s contributions, so it can only go away with the goal.'];
        }

        return in_array('name', $sent, true)
            ? ['name' => 'This label carries the name of the savings goal it tracks, so it is renamed with the goal. Its color can still be changed.']
            : [];
    }

    public function create(User $user, Space $space, string $name, string $color): Label
    {
        $this->assertNameFree($space, $name, null);

        $label = new Label([
            'user_id' => $user->id,
            'space_id' => $space->id,
            'name' => $name,
            'color' => $color,
        ]);
        $label->save();

        return $label;
    }

    /**
     * Change only the fields in $changes.
     *
     * @param  array<string, mixed>  $changes  `name` and/or `color`
     */
    public function update(Label $label, Space $space, array $changes): Label
    {
        if (array_key_exists('name', $changes)) {
            $this->assertNameFree($space, (string) $changes['name'], $label->id);

            $label->name = (string) $changes['name'];
        }

        if (array_key_exists('color', $changes)) {
            $label->color = (string) $changes['color'];
        }

        $label->save();

        return $label;
    }

    private function assertNameFree(Space $space, string $name, ?string $ignoreId): void
    {
        $taken = Label::query()
            ->forSpace($space)
            ->where('name', $name)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => self::NameTakenMessage]);
        }
    }
}
