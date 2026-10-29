<?php

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\withHeaders;

/**
 * @return array<string, string>
 */
function categoriesWriter(User $user): array
{
    return apiV1Bearer($user, ['api:read', 'api:write']);
}

function sharedCategorySpace(User $member, User $owner): Space
{
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    return $space;
}

describe('list', function () {
    it('lists the categories of the personal space, newest first', function () {
        $user = User::factory()->create();
        $older = Category::factory()->create(['user_id' => $user->id, 'created_at' => '2026-01-14 09:30:00']);
        $newer = Category::factory()->childOf($older)->create([
            'name' => 'Groceries',
            'icon' => 'ShoppingBag',
            'color' => 'green',
            'created_at' => '2026-01-15 09:30:00',
        ]);
        Category::factory()->create();

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/categories')
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$newer->id, $older->id])
            ->assertJsonPath('data.0', [
                'id' => $newer->id,
                'space_id' => $user->personalSpace->id,
                'name' => 'Groceries',
                'icon' => 'ShoppingBag',
                'color' => 'green',
                'type' => $older->type->value,
                'cashflow_direction' => $older->cashflow_direction->value,
                'parent_id' => $older->id,
                'created_at' => '2026-01-15T09:30:00+00:00',
            ]);
    });

    it('lists only your own categories of a shared space when asked, and refuses one the user cannot access', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedCategorySpace($user, $owner);
        Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        $mine = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
        Category::factory()->create(['user_id' => $user->id]);
        $headers = apiV1Bearer($user);

        withHeaders($headers)
            ->getJson('/api/v1/categories?space='.$space->id)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$mine->id]);

        withHeaders($headers)
            ->getJson('/api/v1/categories?space='.Space::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('space');
    });

    it('pages through the categories with a cursor', function () {
        $user = User::factory()->create();
        $categories = collect(range(1, 3))->map(fn (int $days): Category => Category::factory()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays($days),
        ]));
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson('/api/v1/categories?limit=2')
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('data.*.id', [$categories[0]->id, $categories[1]->id]);

        withHeaders($headers)->getJson('/api/v1/categories?limit=2&starting_after='.$categories[1]->id)
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$categories[2]->id]);

        withHeaders($headers)->getJson('/api/v1/categories?starting_after='.Category::factory()->create()->id)
            ->assertBadRequest()
            ->assertJsonPath('code', 'invalid_cursor');
    });
});

describe('show', function () {
    it('shows a category of the space, and 404s on someone else\'s', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id]);
        $headers = apiV1Bearer($user);

        withHeaders($headers)
            ->getJson('/api/v1/categories/'.$category->id)
            ->assertOk()
            ->assertJsonPath('data.id', $category->id);

        withHeaders($headers)
            ->getJson('/api/v1/categories/'.Category::factory()->create()->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });
});

describe('create', function () {
    it('creates a root category whose cashflow direction follows its type', function (string $type, ?string $sent, string $expected) {
        $user = User::factory()->create();

        withHeaders(categoriesWriter($user))
            ->postJson('/api/v1/categories', array_filter([
                'name' => 'Rainy day',
                'icon' => 'PiggyBank',
                'color' => 'blue',
                'type' => $type,
                'cashflow_direction' => $sent,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.type', $type)
            ->assertJsonPath('data.cashflow_direction', $expected)
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.space_id', $user->personalSpace->id);
    })->with([
        'savings is always an outflow' => ['savings', 'inflow', 'outflow'],
        'a transfer keeps the one sent' => ['transfer', 'inflow', 'inflow'],
        'a transfer defaults to hidden' => ['transfer', null, 'hidden'],
        'an expense is hidden' => ['expense', 'outflow', 'hidden'],
    ]);

    it('creates a child that takes its parent\'s type and direction', function () {
        $user = User::factory()->create();
        $parent = Category::factory()->create([
            'user_id' => $user->id,
            'type' => CategoryType::Transfer,
            'cashflow_direction' => CategoryCashflowDirection::Outflow,
        ]);

        withHeaders(categoriesWriter($user))
            ->postJson('/api/v1/categories', ['name' => 'To savings', 'icon' => 'Wallet', 'color' => 'teal', 'type' => 'income', 'parent_id' => $parent->id])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $parent->id)
            ->assertJsonPath('data.type', 'transfer')
            ->assertJsonPath('data.cashflow_direction', 'outflow');
    });

    it('refuses invalid and unknown fields, a parent of another space and a sibling name clash', function () {
        $user = User::factory()->create();
        Category::factory()->create(['user_id' => $user->id, 'name' => 'Food']);
        $headers = categoriesWriter($user);

        withHeaders($headers)
            ->postJson('/api/v1/categories', ['icon' => 'Home', 'color' => 'mauve', 'type' => 'gift', 'parent_id' => Category::factory()->create()->id, 'colour' => 'red'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'color', 'type', 'parent_id', 'colour']);

        withHeaders($headers)
            ->postJson('/api/v1/categories', ['name' => 'Food', 'icon' => 'Home', 'color' => 'red', 'type' => 'expense'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'A category with that name already exists at this level.');

        expect($user->categories()->count())->toBe(1);
    });

    it('refuses a name or icon longer than its column', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'icon' => 'Plane']);
        $headers = categoriesWriter($user);

        withHeaders($headers)
            ->postJson('/api/v1/categories', ['name' => str_repeat('a', 256), 'icon' => str_repeat('a', 256), 'color' => 'red', 'type' => 'expense'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'icon']);

        withHeaders($headers)
            ->patchJson('/api/v1/categories/'.$category->id, ['icon' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('icon');

        expect($user->categories()->count())->toBe(1)
            ->and($category->fresh()->icon)->toBe('Plane');
    });

    it('refuses nesting deeper than 3 levels', function () {
        $user = User::factory()->create();
        $root = Category::factory()->create(['user_id' => $user->id]);
        $leaf = Category::factory()->childOf(Category::factory()->childOf($root)->create())->create();

        withHeaders(categoriesWriter($user))
            ->postJson('/api/v1/categories', ['name' => 'Too deep', 'icon' => 'Home', 'color' => 'red', 'type' => 'expense', 'parent_id' => $leaf->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.parent_id.0', 'Categories can only be nested up to 3 levels deep.');
    });

    it('refuses a read-only token', function () {
        $user = User::factory()->create();

        withHeaders(apiV1Bearer($user))
            ->postJson('/api/v1/categories', ['name' => 'Travel', 'icon' => 'Plane', 'color' => 'blue', 'type' => 'expense'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($user->categories()->count())->toBe(0);
    });

    it('replays a POST with the same idempotency key instead of creating a second category', function () {
        $user = User::factory()->create();
        $headers = [...categoriesWriter($user), 'Idempotency-Key' => 'create-travel'];
        $body = ['name' => 'Travel', 'icon' => 'Plane', 'color' => 'blue', 'type' => 'expense'];

        $first = withHeaders($headers)->postJson('/api/v1/categories', $body)->assertCreated();
        $replay = withHeaders($headers)->postJson('/api/v1/categories', $body)->assertCreated();

        $replay->assertHeader('Idempotent-Replayed', 'true');
        expect($replay->json('data.id'))->toBe($first->json('data.id'))
            ->and($user->categories()->count())->toBe(1);
    });
});

describe('update', function () {
    it('changes only the fields it is sent', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Travel', 'icon' => 'Plane', 'color' => 'blue', 'type' => CategoryType::Expense]);

        withHeaders(categoriesWriter($user))
            ->patchJson('/api/v1/categories/'.$category->id, ['name' => 'Holidays'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Holidays')
            ->assertJsonPath('data.icon', 'Plane')
            ->assertJsonPath('data.color', 'blue')
            ->assertJsonPath('data.type', 'expense');
    });

    it('moves a category under a new parent and cascades the type down its subtree', function () {
        $user = User::factory()->create();
        $savings = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Savings, 'cashflow_direction' => CategoryCashflowDirection::Outflow]);
        $expense = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $child = Category::factory()->childOf($expense)->create();
        $grandchild = Category::factory()->childOf($child)->create();
        $headers = categoriesWriter($user);

        withHeaders($headers)
            ->patchJson('/api/v1/categories/'.$child->id, ['parent_id' => $savings->id])
            ->assertOk()
            ->assertJsonPath('data.parent_id', $savings->id)
            ->assertJsonPath('data.type', 'savings')
            ->assertJsonPath('data.cashflow_direction', 'outflow');

        expect($grandchild->fresh()->type)->toBe(CategoryType::Savings);

        withHeaders($headers)
            ->patchJson('/api/v1/categories/'.$child->id, ['parent_id' => null, 'type' => 'income'])
            ->assertOk()
            ->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.type', 'income')
            ->assertJsonPath('data.cashflow_direction', 'hidden');
    });

    it('refuses to nest a category under one of its own children', function () {
        $user = User::factory()->create();
        $parent = Category::factory()->create(['user_id' => $user->id]);
        $child = Category::factory()->childOf($parent)->create();

        withHeaders(categoriesWriter($user))
            ->patchJson('/api/v1/categories/'.$parent->id, ['parent_id' => $child->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.parent_id.0', 'A category cannot be nested under itself or one of its children.');

        expect($parent->fresh()->parent_id)->toBeNull();
    });

    it('404s on another user\'s category and refuses a read-only token', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Travel']);

        withHeaders(categoriesWriter($user))
            ->patchJson('/api/v1/categories/'.Category::factory()->create()->id, ['name' => 'Mine now'])
            ->assertNotFound();

        withHeaders(apiV1Bearer($user))
            ->patchJson('/api/v1/categories/'.$category->id, ['name' => 'Holidays'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($category->fresh()->name)->toBe('Travel');
    });
});

describe('ownership', function () {
    it('answers 404 for another member\'s category of a shared space, on read and write', function () {
        $member = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedCategorySpace($member, $owner);
        $theirs = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Theirs']);
        $headers = categoriesWriter($member);

        withHeaders($headers)
            ->getJson('/api/v1/categories/'.$theirs->id.'?space='.$space->id)
            ->assertNotFound();

        withHeaders($headers)
            ->patchJson('/api/v1/categories/'.$theirs->id.'?space='.$space->id, ['name' => 'Mine'])
            ->assertNotFound();

        withHeaders($headers)
            ->deleteJson('/api/v1/categories/'.$theirs->id.'?space='.$space->id)
            ->assertNotFound();

        expect($theirs->fresh()->name)->toBe('Theirs');
    });

    it('refuses to nest a category under one another member created', function () {
        $member = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedCategorySpace($member, $owner);
        $theirs = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);

        withHeaders(categoriesWriter($member))
            ->postJson('/api/v1/categories', ['space' => $space->id, 'name' => 'Mine', 'icon' => 'Home', 'color' => 'red', 'type' => 'expense', 'parent_id' => $theirs->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.parent_id.0', 'The parent id is not a category of yours in this space.');

        expect($member->categories()->count())->toBe(0);
    });

    it('lets a member reuse a name another member of the space already took', function () {
        $member = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedCategorySpace($member, $owner);
        Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Groceries', 'parent_id' => null]);

        withHeaders(categoriesWriter($member))
            ->postJson('/api/v1/categories', ['space' => $space->id, 'name' => 'Groceries', 'icon' => 'Home', 'color' => 'red', 'type' => 'expense'])
            ->assertCreated();
    });
});

describe('delete', function () {
    it('handles the children as the strategy says and uncategorizes the transactions', function (string $query, Closure $expectation) {
        $user = User::factory()->create();
        $root = Category::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->childOf($root)->create();
        $child = Category::factory()->childOf($category)->create();
        $transaction = Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => Account::factory()->create(['user_id' => $user->id])->id,
            'category_id' => $category->id,
        ]);

        withHeaders(categoriesWriter($user))
            ->deleteJson('/api/v1/categories/'.$category->id.$query)
            ->assertOk()
            ->assertJson(['id' => $category->id, 'deleted' => true]);

        expect(Category::query()->find($category->id))->toBeNull()
            ->and($transaction->fresh()->category_id)->toBeNull();

        $expectation(Category::query()->find($child->id), $root);
    })->with([
        'reparent by default' => ['', fn (?Category $child, Category $root) => expect($child->parent_id)->toBe($root->id)],
        'promote' => ['?strategy=promote', fn (?Category $child) => expect($child->parent_id)->toBeNull()],
        'cascade' => ['?strategy=cascade', fn (?Category $child) => expect($child)->toBeNull()],
    ]);

    it('refuses an unknown strategy and a move that would clash, deleting nothing', function () {
        $user = User::factory()->create();
        $root = Category::factory()->create(['user_id' => $user->id, 'name' => 'Food']);
        $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Leisure']);
        Category::factory()->childOf($category)->create(['name' => 'Food']);
        $headers = categoriesWriter($user);

        withHeaders($headers)
            ->deleteJson('/api/v1/categories/'.$category->id.'?strategy=orphan')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('strategy');

        withHeaders($headers)
            ->deleteJson('/api/v1/categories/'.$category->id.'?strategy=promote')
            ->assertUnprocessable()
            ->assertJsonPath('errors.strategy.0', 'A category with the same name already exists at the destination level. Rename it first.');

        expect($category->fresh())->not->toBeNull()
            ->and($root->fresh())->not->toBeNull();
    });

    it('404s on another user\'s category and refuses a read-only token', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id]);

        withHeaders(categoriesWriter($user))
            ->deleteJson('/api/v1/categories/'.Category::factory()->create()->id)
            ->assertNotFound();

        withHeaders(apiV1Bearer($user))
            ->deleteJson('/api/v1/categories/'.$category->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($category->fresh())->not->toBeNull();
    });
});
