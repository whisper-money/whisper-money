<?php

namespace App\Mcp\Tools;

use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionSplitter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Split one transaction into 2-20 parts, each with its own category and labels. Works on bank transactions too. The parts must add up to the original and all move money the same way.')]
class SplitTransaction extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->string()->description('Id of the transaction to split. A part of an existing split cannot be split again — merge it back first.')->required(),
            'splits' => $schema->array()->items($schema->object([
                'amount' => $schema->integer()->description('Signed amount in minor units (cents). Must have the same sign as the original: parts of an expense are all negative.')->required(),
                'category_id' => $schema->string()->description('Category for this part. Omit to leave it uncategorized.'),
                'label_ids' => $schema->array()->items($schema->string())->description('Label ids for this part. Defaults to none; the original\'s labels are not copied.'),
            ]))->description('The parts to create, 2 to '.TransactionSplitter::MAX_PARTS.' of them. Their amounts must add up to the original amount exactly.')->required(),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);
        $transaction = $this->transactionInSpace($request, $space);

        $request->validate([
            'splits' => ['required', 'array', 'min:2', 'max:'.TransactionSplitter::MAX_PARTS],
            'splits.*.amount' => ['required', 'integer'],
            'splits.*.category_id' => ['nullable', 'string'],
            'splits.*.label_ids' => ['nullable', 'array'],
            'splits.*.label_ids.*' => ['required', 'string'],
        ]);

        $parts = app(TransactionSplitter::class)
            ->split($transaction, $this->partsFromRequest($request, $space));

        return $this->json([
            'space_id' => $space->id,
            'split_into' => $parts->count(),
            'parts' => $parts->map(fn (Transaction $part): array => $this->presentTransaction($part))->values()->all(),
        ]);
    }

    /**
     * The requested parts, with every category and label id checked against the
     * space before anything is written.
     *
     * @return list<array<string, mixed>>
     */
    private function partsFromRequest(Request $request, Space $space): array
    {
        $rows = collect((array) $request->get('splits', []))
            ->map(fn (mixed $row): array => (array) $row);

        $this->assertIdsExistInSpace(
            Category::query()->forSpace($space),
            $rows->pluck('category_id')->filter()->unique()->values(),
            'category',
            'list_categories',
            $space,
        );

        $this->assertIdsExistInSpace(
            Label::query()->forSpace($space),
            $rows->pluck('label_ids')->flatten()->filter()->unique()->values(),
            'label',
            'list_labels',
            $space,
        );

        return $rows->map(fn (array $row): array => [
            'amount' => (int) ($row['amount'] ?? 0),
            'category_id' => $row['category_id'] ?? null,
            'label_ids' => array_values(array_filter((array) ($row['label_ids'] ?? []))),
        ])->values()->all();
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @param  Collection<int, mixed>  $ids
     *
     * @throws ValidationException
     */
    private function assertIdsExistInSpace(Builder $query, Collection $ids, string $noun, string $listTool, Space $space): void
    {
        if ($ids->isEmpty()) {
            return;
        }

        $found = $query->whereIn('id', $ids->all())->pluck('id');

        if ($found->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'splits' => "One or more {$noun} ids do not exist in space {$space->id}. Call {$listTool} to see valid ids.",
            ]);
        }
    }
}
