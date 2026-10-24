<?php

use Tests\Support\DataVersionWriteScanner;

/*
 * Model events bump the data version on their own; these writes do not. Every
 * one of them must either bump by hand or say why it does not need to, in
 * tests/Fixtures/data-version-guard-allowlist.json (file => statement => where
 * the bump happens).
 */

function dataVersionAllowlist(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/data-version-guard-allowlist.json'), true, flags: JSON_THROW_ON_ERROR);
}

function describeHits(array $hits): string
{
    return implode("\n", array_map(fn (array $hit): string => "{$hit['file']}:{$hit['line']}  {$hit['statement']}", $hits));
}

it('accounts for every write that skips model events', function () {
    $basePath = dirname(__DIR__, 2);
    $hits = DataVersionWriteScanner::scan($basePath.'/app', $basePath);
    $unlisted = DataVersionWriteScanner::unlisted($hits, dataVersionAllowlist());

    expect($unlisted)->toBeEmpty(
        "These writes skip model events, so they do not bump the user's data version (App\\Services\\Cache\\DataVersion).\n"
        ."Bump after the write, then add each one to tests/Fixtures/data-version-guard-allowlist.json saying where:\n"
        .describeHits($unlisted),
    );
});

it('has no allowlist entry left over from a write that is gone', function () {
    $basePath = dirname(__DIR__, 2);
    $hits = DataVersionWriteScanner::scan($basePath.'/app', $basePath);

    expect(DataVersionWriteScanner::stale($hits, dataVersionAllowlist()))->toBeEmpty();
});

it('flags a new builder write on a watched table and ignores unwatched ones', function () {
    $directory = sys_get_temp_dir().'/data-version-guard-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.'/Fixture.php', <<<'PHP'
        <?php

        namespace Fixture;

        // DB::table('transactions')->delete() in a comment is not a write.
        DB::table('transactions')
            ->where('user_id', $userId)
            ->update(['category_id' => null]);

        DB::table('jobs')->delete();
        $transaction->save();
        Transaction::insert($rows);
        Transaction::onlyTrashed()->where('user_id', $userId)->restore();
        PHP);

    try {
        $hits = DataVersionWriteScanner::scan($directory, $directory);
    } finally {
        unlink($directory.'/Fixture.php');
        rmdir($directory);
    }

    expect(array_column($hits, 'line'))->toBe([6, 8, 12, 13])
        ->and(DataVersionWriteScanner::unlisted($hits, []))->toHaveCount(4)
        ->and(DataVersionWriteScanner::unlisted($hits, [
            'Fixture.php' => [
                "DB::table('transactions')" => 'bumped right after',
                "DB::table('transactions') ->where('user_id', \$userId) ->update(" => 'bumped right after',
                'Transaction::insert(' => 'bumped right after',
                "Transaction::onlyTrashed()->where('user_id', \$userId)->restore(" => 'bumped right after',
            ],
        ]))->toBeEmpty();
});

it('reports allowlist entries that match nothing', function () {
    expect(DataVersionWriteScanner::stale([], ['app/Gone.php' => ['Transaction::query()->update(' => 'bumped']]))
        ->toBe(['app/Gone.php: Transaction::query()->update(']);
});
