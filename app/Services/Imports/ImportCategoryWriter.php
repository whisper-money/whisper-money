<?php

namespace App\Services\Imports;

use App\Enums\CategoryType;
use App\Enums\ImportCategoryAction;
use App\Models\Category;
use App\Models\Import;
use Illuminate\Support\Collection;

/**
 * Turns the categories plan of a full import into category ids: reuses the
 * ones matched to the user's own and creates the rest, parents before their
 * children, so a file's "Parent, Child" arrives as the same tree.
 *
 * Each plan entry is what StoreFullImportRequest validated: a `key`, an
 * `action`, and the fields that action needs.
 */
class ImportCategoryWriter
{
    /**
     * @param  list<array<string, mixed>>  $plan
     * @return array<string, string> plan key => category id
     */
    public function write(Import $import, array $plan): array
    {
        $entries = collect($plan)->keyBy('key');
        $ids = [];

        foreach ($entries->where('action', ImportCategoryAction::Match->value) as $key => $entry) {
            $ids[$key] = (string) $entry['category_id'];
        }

        $created = 0;

        foreach ($this->parentsFirst($entries->where('action', ImportCategoryAction::Create->value), $entries) as $key => $entry) {
            $parentId = isset($entry['parent_key']) ? ($ids[$entry['parent_key']] ?? null) : null;
            [$category, $wasCreated] = $this->findOrCreate($import, $entry, $parentId);
            $ids[$key] = $category->id;
            $created += $wasCreated ? 1 : 0;
        }

        $import->recordStats(['categories' => [
            'created' => $created,
            'matched' => count($ids) - $created,
        ]]);

        return $ids;
    }

    /**
     * Order the entries to create so each comes after its parent: sorting by
     * how far down the plan's own tree an entry sits is enough, the tree being
     * at most three levels deep.
     *
     * @param  Collection<array-key, array<string, mixed>>  $creates
     * @param  Collection<array-key, array<string, mixed>>  $entries
     * @return Collection<array-key, array<string, mixed>>
     */
    private function parentsFirst(Collection $creates, Collection $entries): Collection
    {
        return $creates->sortBy(function (array $entry) use ($entries): int {
            $depth = 0;
            $parentKey = $entry['parent_key'] ?? null;

            while ($parentKey !== null && $depth <= Category::MAX_DEPTH) {
                $depth++;
                $parentKey = $entries->get($parentKey)['parent_key'] ?? null;
            }

            return $depth;
        });
    }

    /**
     * A category of that name may already sit under the same parent, from an
     * earlier import of the same file or made by hand since the wizard looked:
     * names are unique among siblings, so it is reused rather than refused.
     *
     * A child takes its parent's type and cashflow direction, the same rule the
     * category form follows, so a subtree never mixes types.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: Category, 1: bool}
     */
    private function findOrCreate(Import $import, array $entry, ?string $parentId): array
    {
        $existing = Category::query()
            ->where('user_id', $import->user_id)
            ->where('parent_id', $parentId)
            ->where('name', $entry['name'])
            ->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        $parent = $parentId !== null ? Category::query()->find($parentId) : null;
        $type = $parent !== null ? $parent->type : CategoryType::from((string) $entry['type']);

        $category = Category::query()->create([
            'user_id' => $import->user_id,
            'space_id' => $import->space_id,
            'parent_id' => $parentId,
            'name' => $entry['name'],
            'icon' => $entry['icon'],
            'color' => $entry['color'],
            'type' => $type,
            'cashflow_direction' => $parent !== null ? $parent->cashflow_direction : $type->defaultCashflowDirection(),
            'import_id' => $import->id,
        ]);

        return [$category, true];
    }
}
