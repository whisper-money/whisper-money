<?php

namespace App\Services\Ai\Contracts;

use App\Models\Transaction;
use App\Services\Ai\CategoryCatalog;
use Illuminate\Support\Collection;

interface CategorizationBackend
{
    /**
     * Ask the model to place each transaction in one of the catalog's leaf
     * categories. Returns the raw, unvalidated results; a missing
     * "category_index" means no category fits.
     *
     * @param  Collection<int, Transaction>  $chunk
     * @return list<array{ref: string, category_index?: int, confidence: float, merchant_unambiguous: bool}>
     */
    public function categorize(Collection $chunk, CategoryCatalog $catalog): array;

    /**
     * The model recorded as `ai_model` on the transactions it categorized.
     */
    public function model(): string;
}
