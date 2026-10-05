<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\CreateDefaultCategories;
use App\Models\Category;
use App\Models\Import;
use App\Models\User;
use Tests\TestCase;

/**
 * Drives a full import the way the wizard does: the plan, the rows in
 * chunks, then the start that queues the job (the sync queue in tests runs it
 * on the spot). The rows are already what the browser sends: ISO dates and
 * amounts in minor units.
 */
final class FullImportFixtures
{
    /**
     * A user a week past onboarding, inside the window, with the seeded
     * categories every new account starts with.
     */
    public static function user(array $attributes = []): User
    {
        $user = User::factory()->onboarded()->create([
            'onboarded_at' => now()->subWeek(),
            'currency_code' => 'EUR',
            ...$attributes,
        ]);

        app(CreateDefaultCategories::class)->handle($user);

        return $user;
    }

    /** One of the user's seeded categories, by its English name. */
    public static function seeded(User $user, string $name): Category
    {
        return Category::query()->where('user_id', $user->id)->where('name', $name)->firstOrFail();
    }

    /**
     * @param  list<array<string, mixed>>  $accounts
     * @param  list<array<string, mixed>>  $categories
     * @return array<string, mixed>
     */
    public static function plan(array $accounts, array $categories = [], string $mode = 'add'): array
    {
        return [
            'source' => 'banktrack',
            'file_name' => 'banktrack-export.csv',
            'mode' => $mode,
            ...$mode === 'wipe' ? ['confirm_wipe' => true] : [],
            'profile' => ['columns' => ['date' => 'Fecha', 'amount' => 'Importe'], 'date_format' => 'DD-MM-YYYY', 'category_separator' => ',', 'split_accounts' => false],
            'accounts' => $accounts,
            'categories' => $categories,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function newAccount(string $key, string $name, array $attributes = []): array
    {
        return [
            'key' => $key,
            'action' => 'create',
            'name' => $name,
            'type' => 'checking',
            'currency_code' => 'EUR',
            'bank_id' => null,
            ...$attributes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(string $accountKey, string $date, int $amount, string $description, array $attributes = []): array
    {
        return [
            'account_key' => $accountKey,
            'date' => $date,
            'amount' => $amount,
            'description' => $description,
            ...$attributes,
        ];
    }

    /**
     * Post the plan and its rows and start the import, asserting each step
     * the browser would see succeed.
     *
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $transactions
     * @param  list<array<string, mixed>>  $balances
     */
    public static function run(TestCase $test, User $user, array $plan, array $transactions, array $balances = []): Import
    {
        $id = $test->actingAs($user)->postJson(route('api.full-imports.store'), [
            ...$plan,
            'expected_transactions' => count($transactions),
            'expected_balances' => count($balances),
        ])->assertCreated()->json('id');

        foreach (['transactions' => $transactions, 'balances' => $balances] as $kind => $rows) {
            foreach (array_chunk($rows, 500) as $position => $chunk) {
                $test->postJson(route('api.full-imports.chunks.store', $id), [
                    'kind' => $kind,
                    'position' => $position,
                    'rows' => $chunk,
                ])->assertOk();
            }
        }

        $test->postJson(route('api.full-imports.start', $id))->assertOk();

        return Import::query()->findOrFail($id);
    }
}
