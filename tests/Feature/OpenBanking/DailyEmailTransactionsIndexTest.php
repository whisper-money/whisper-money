<?php

use Illuminate\Support\Facades\Schema;

it('has a composite index supporting the daily synced-email transaction query', function () {
    $indexes = collect(Schema::getIndexes('transactions'));

    $match = $indexes->first(fn (array $index): bool => $index['columns'] === ['user_id', 'source', 'created_at']
    );

    expect($match)->not->toBeNull(
        'Expected a (user_id, source, created_at) index on transactions for the daily email query.'
    );
    expect($match['name'])->toBe('idx_transactions_user_source_created');
});
