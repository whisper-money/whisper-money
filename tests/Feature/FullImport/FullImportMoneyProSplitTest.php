<?php

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategorySource;
use App\Jobs\CategorizeImportedTransactionsJob;
use App\Models\AutomationRule;
use App\Models\Import;
use App\Models\ImportChunk;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategoryCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FullImportFixtures as Fixtures;
use Tests\TestCase;

// The master switch is off by default on this instance (nobody can start an
// import); these tests run with it on and the onboarding window open.
beforeEach(function () {
    config(['full_import.enabled' => true]);
});

/**
 * The plan of a Money Pro export of one new account, with one category of
 * its own the user already has.
 *
 * @return array<string, mixed>
 */
function moneyProSplitPlan(User $user, string $source = 'money_pro'): array
{
    return [
        ...Fixtures::plan([Fixtures::newAccount('a0', 'Cuenta Ficticia')], [
            ['key' => 'c0', 'action' => 'match', 'category_id' => Fixtures::seeded($user, 'Groceries')->id],
        ]),
        'source' => $source,
        'file_name' => 'money-pro.csv',
    ];
}

/**
 * A row Money Pro had split, as the wizard sends it: no category, the
 * original cell in the notes and the split marker.
 *
 * @return array<string, mixed>
 */
function moneyProSplitRow(string $date, int $amount, string $description, string $cell): array
{
    return Fixtures::row('a0', $date, $amount, $description, [
        'notes' => "Dividida en Money Pro: {$cell}",
        'source_split' => true,
    ]);
}

/**
 * Run a Money Pro import of these rows.
 *
 * @param  list<array<string, mixed>>  $rows
 */
function runMoneyProSplitImport(TestCase $test, User $user, array $rows): Import
{
    return Fixtures::run($test, $user, moneyProSplitPlan($user), $rows);
}

it('stages the split marker of a Money Pro row, and refuses it from any other app', function () {
    $user = Fixtures::user();
    $this->actingAs($user);

    $store = fn (string $source) => $this->postJson(route('api.full-imports.store'), [
        ...moneyProSplitPlan($user, $source),
        'expected_transactions' => 1,
        'expected_balances' => 0,
    ])->assertCreated()->json('id');
    $chunk = fn (string $id, mixed $marker) => $this->postJson(route('api.full-imports.chunks.store', $id), [
        'kind' => 'transactions',
        'position' => 0,
        'rows' => [Fixtures::row('a0', '2026-09-01', -4250, 'Mercadona', ['source_split' => $marker])],
    ]);

    $moneyPro = $store('money_pro');

    $chunk($moneyPro, 'yes')->assertUnprocessable()->assertJsonValidationErrors('rows.0.source_split');
    $chunk($moneyPro, true)->assertOk();

    expect(ImportChunk::query()->where('import_id', $moneyPro)->sole()->rows[0]['source_split'])->toBeTrue();

    $chunk($store('banktrack'), true)->assertUnprocessable()->assertJsonValidationErrors('rows.0.source_split');
});

it('imports a row split in Money Pro whole, uncategorized, with its note and one label per space that no rule overrides', function () {
    $user = Fixtures::user();
    // Seeded in English, so the categories are found by their English names.
    $user->update(['locale' => 'es']);
    $groceries = Fixtures::seeded($user, 'Groceries');
    // A rule that files every row of the file, the split ones included, if it ran on them.
    AutomationRule::factory()->create([
        'user_id' => $user->id,
        'priority' => 1,
        'rules_json' => ['in' => ['mercadona', ['var' => 'description']]],
        'action_category_id' => $groceries->id,
    ]);

    $import = runMoneyProSplitImport($this, $user, [
        moneyProSplitRow('2026-09-01', -4250, 'Mercadona', 'Supermercado : Alimentación Compras Salud'),
        moneyProSplitRow('2026-09-02', -3000, 'Mercadona restaurante', 'Restaurantes Restaurantes'),
        Fixtures::row('a0', '2026-09-03', -1000, 'Mercadona'),
        Fixtures::row('a0', '2026-09-04', -500, 'Fruteria', ['category_key' => 'c0']),
    ]);

    $rows = Transaction::query()->where('import_id', $import->id)->with('labels')->orderBy('transaction_date')->get();
    [$first, $second, $filedByRule, $filedByFile] = $rows->all();
    $label = Label::query()->where('user_id', $user->id)->sole();

    expect($label->name)->toBe('Dividida en Money Pro')
        ->and($label->space_id)->toBe($first->space_id)
        ->and($first->category_id)->toBeNull()
        ->and($first->category_source)->toBe(CategorySource::Manual)
        ->and($first->notes)->toBe('Dividida en Money Pro: Supermercado : Alimentación Compras Salud')
        ->and($first->labels->pluck('id')->all())->toBe([$label->id])
        ->and($second->category_id)->toBeNull()
        ->and($second->labels->pluck('id')->all())->toBe([$label->id])
        ->and($first->splits()->exists())->toBeFalse()
        // The rule runs on the rest of the file.
        ->and($filedByRule->category_id)->toBe($groceries->id)
        ->and($filedByRule->category_source)->toBe(CategorySource::Rule)
        ->and($filedByRule->labels)->toBeEmpty()
        ->and($filedByFile->category_id)->toBe($groceries->id)
        ->and($filedByFile->labels)->toBeEmpty();

    // The next import finds the label the first one made.
    $next = runMoneyProSplitImport($this, $user, [
        moneyProSplitRow('2026-09-05', -2000, 'Compra', 'Compras Compras Salud'),
    ]);

    expect(Label::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Transaction::query()->where('import_id', $next->id)->sole()->labels->pluck('id')->all())->toBe([$label->id]);
});

it('leaves the rows split in Money Pro out of the import\'s AI pass', function () {
    $user = Fixtures::user();
    $user->recordAiConsent();

    Queue::fake([CategorizeImportedTransactionsJob::class]);

    $onlySplit = runMoneyProSplitImport($this, $user, [
        moneyProSplitRow('2026-09-01', -4250, 'Mercadona', 'Compras Salud'),
    ]);

    expect($onlySplit->stats['uncategorized'])->toBe(1)
        ->and($onlySplit->stats['ai']['status'])->toBe('skipped');

    $import = runMoneyProSplitImport($this, $user, [
        moneyProSplitRow('2026-09-02', -3000, 'Uber split', 'Coche: Gasolina Viajes: Playa'),
        Fixtures::row('a0', '2026-09-03', -1000, 'Netflix'),
    ]);

    expect($import->stats['uncategorized'])->toBe(2)
        ->and($import->stats['ai'])->toMatchArray(['status' => 'queued', 'total' => 1]);

    $catalog = CategoryCatalog::forUser($user);
    $targetId = Fixtures::seeded($user, 'Online services')->id;
    $index = 0;

    while ($catalog->categoryIdForIndex($index) !== $targetId) {
        $index++;
    }

    $prompts = [];

    TransactionCategorizationAgent::fake(function (string $prompt) use ($index, &$prompts): array {
        $prompts[] = $prompt;
        preg_match_all('/"ref":"([0-9a-f-]+)"/', $prompt, $matches);

        return ['results' => array_map(fn (string $ref): array => [
            'ref' => $ref,
            'category_index' => $index,
            'confidence' => 0.95,
            'merchant_unambiguous' => false,
        ], $matches[1])];
    });

    app()->call([new CategorizeImportedTransactionsJob($import), 'handle']);

    $split = Transaction::query()->where('import_id', $import->id)->where('description', 'Uber split')->sole();

    expect($split->category_id)->toBeNull()
        ->and($split->category_source)->toBe(CategorySource::Manual)
        ->and(Transaction::query()->where('import_id', $import->id)->where('description', 'Netflix')->value('category_id'))->toBe($targetId)
        ->and(implode("\n", $prompts))->not->toContain('Uber split')
        ->and($import->fresh()->stats['ai'])->toMatchArray(['status' => 'done', 'total' => 1, 'applied' => 1])
        ->and($import->fresh()->stats['uncategorized'])->toBe(1);
});

it('takes the split rows out on undo and leaves their label', function () {
    $user = Fixtures::user();

    $import = runMoneyProSplitImport($this, $user, [
        moneyProSplitRow('2026-09-01', -4250, 'Mercadona', 'Restaurantes Restaurantes Restaurantes'),
    ]);
    $label = Label::query()->where('user_id', $user->id)->sole();

    expect(DB::table('label_transaction')->where('label_id', $label->id)->count())->toBe(1);

    $this->actingAs($user)
        ->delete(route('full-import.destroy', $import))
        ->assertRedirect(route('full-import.index'));

    expect(Transaction::withTrashed()->where('import_id', $import->id)->exists())->toBeFalse()
        ->and(DB::table('label_transaction')->where('label_id', $label->id)->exists())->toBeFalse()
        ->and($label->fresh())->not->toBeNull()
        ->and($label->fresh()->name)->toBe('Split in Money Pro');
});
