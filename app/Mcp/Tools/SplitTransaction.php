<?php

namespace App\Mcp\Tools;

use App\Features\TransactionSplitting;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionSplitter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Pennant\Feature;

#[IsDestructive]
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
                'amount' => $schema->integer()->description('Signed amount in the transaction currency\'s minor units. Must have the same sign as the original: parts of an expense are all negative.')->required(),
                'category_id' => $schema->string()->description('Category for this part. Omit to leave it uncategorized.'),
                'label_ids' => $schema->array()->items($schema->string())->description('Label ids for this part. Defaults to none; the original\'s labels are not copied.'),
            ]))->description('The parts to create, 2 to '.TransactionSplitter::MAX_PARTS.' of them. Their amounts must add up to the original amount exactly.')->required(),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate(TransactionSplitter::partRules(), TransactionSplitter::partMessages());

        $space = $this->resolveSpace($request, $user);
        $transaction = $this->transactionInSpace($request, $space);
        $parts = $this->partsFromRequest($request, $space);

        if (! Feature::for($user)->active(TransactionSplitting::class)) {
            return Response::error('Creating transaction splits is currently disabled. Existing splits may still be merged back with merge_transaction_splits.');
        }

        $created = app(TransactionSplitter::class)->split($transaction, $parts);

        return $this->json([
            'space_id' => $space->id,
            'split_into' => $created->count(),
            'parts' => $created->map(fn (Transaction $part): array => $this->presentTransaction($part))->values()->all(),
        ]);
    }

    /**
     * The requested parts, with every category and label id checked to be in
     * the space before anything is written, whichever member keeps it, as the
     * other MCP writes check them. Ids come back as stored.
     *
     * @return list<array{amount: int, category_id: ?string, label_ids: list<string>}>
     */
    private function partsFromRequest(Request $request, Space $space): array
    {
        $rows = collect((array) $request->get('splits', []))->map(fn (mixed $row): array => (array) $row);

        $categories = $this->storedIds($space, $rows->pluck('category_id')->all(), Category::class, 'category', 'list_categories');
        $labels = $this->storedIds($space, $rows->pluck('label_ids')->flatten()->all(), Label::class, 'label', 'list_labels');

        return $rows->map(fn (array $row): array => [
            'amount' => (int) ($row['amount'] ?? 0),
            'category_id' => filled($row['category_id'] ?? null) ? $categories[mb_strtolower((string) $row['category_id'])] : null,
            'label_ids' => array_values(array_map(
                fn (mixed $id): string => $labels[mb_strtolower((string) $id)],
                array_filter((array) ($row['label_ids'] ?? []), filled(...)),
            )),
        ])->values()->all();
    }

    /**
     * Each of $ids in the space, keyed by its lowercase form, as stored.
     *
     * @param  list<mixed>  $ids
     * @param  class-string<Category|Label>  $model
     * @return array<string, string>
     */
    private function storedIds(Space $space, array $ids, string $model, string $noun, string $listTool): array
    {
        $wanted = collect($ids)->filter(filled(...))->map(fn (mixed $id): string => mb_strtolower((string) $id))->unique()->values()->all();

        return $this->rowsInSpace($space, $wanted, 'splits', $model, $noun, $listTool)
            ->mapWithKeys(fn (Category|Label $row): array => [mb_strtolower($row->id) => $row->id])
            ->all();
    }
}
