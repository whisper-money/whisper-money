<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\RealEstateDetail;
use App\Models\User;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;

const VERSIONED_COLLECTIONS = ['accounts', 'categories', 'banks', 'automationRules', 'labels'];

/**
 * An Inertia visit to the dashboard, as the client makes it after the first
 * page load, holding the given once-prop keys.
 *
 * @param  list<string>  $heldKeys
 */
function inertiaVisit(User $user, array $heldKeys = [], string $route = 'dashboard'): TestResponse
{
    return actingAs($user)->get(route($route), array_filter([
        'X-Inertia' => 'true',
        'X-Inertia-Except-Once-Props' => implode(',', $heldKeys),
    ]))->assertOk();
}

/**
 * The once-prop key of each shared collection in a response, keyed by prop.
 *
 * @return array<string, string>
 */
function collectionKeys(TestResponse $response): array
{
    return collect($response->json('onceProps'))
        ->mapWithKeys(fn (array $meta, string $key): array => [$meta['prop'] => $key])
        ->only(VERSIONED_COLLECTIONS)
        ->all();
}

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->onboarded()->create();
    $this->account = Account::factory()->create(['user_id' => $this->user->id, 'type' => AccountType::Checking]);
    $this->category = Category::factory()->create(['user_id' => $this->user->id]);
    $this->label = Label::factory()->create(['user_id' => $this->user->id]);
    $this->rule = AutomationRule::factory()->create(['user_id' => $this->user->id]);

    $this->keys = collectionKeys(inertiaVisit($this->user));

    // Every write below lands on a later second than the read above, the way
    // it does between a person's clicks.
    $this->travel(1)->seconds();
});

/**
 * The collections a visit holding the keys from before the write sends again.
 *
 * @return list<string>
 */
function resentCollections(User $user, array $keys): array
{
    $props = inertiaVisit($user, array_values($keys))->json('props');

    return array_values(array_filter(VERSIONED_COLLECTIONS, fn (string $prop): bool => array_key_exists($prop, $props)));
}

test('the first visit sends every collection with a once key', function () {
    $response = inertiaVisit($this->user);

    expect(collectionKeys($response))->toHaveKeys(VERSIONED_COLLECTIONS)
        ->and($response->json('props.categories.0.id'))->toBe($this->category->id)
        ->and($response->json('props.labels.0.id'))->toBe($this->label->id);
});

test('a visit holding the current keys skips every collection', function () {
    $response = inertiaVisit($this->user, array_values($this->keys));

    foreach (VERSIONED_COLLECTIONS as $prop) {
        $response->assertJsonMissingPath("props.{$prop}");
    }

    // The client still learns the keys, so it keeps the lists it holds.
    expect(collectionKeys($response))->toBe($this->keys);
});

test('a visit holding the keys skips the collection queries', function () {
    $full = countQueries(fn () => inertiaVisit($this->user));
    $held = countQueries(fn () => inertiaVisit($this->user, array_values($this->keys)));

    // Five lists and their eager loads, traded for one fingerprint query.
    expect($held['count'])->toBeLessThan($full['count'] - 4);
});

test('the keys belong to one user', function () {
    $other = User::factory()->onboarded()->create();

    $response = inertiaVisit($other, array_values($this->keys));

    expect(collectionKeys($response))->not->toBe($this->keys);
    foreach (VERSIONED_COLLECTIONS as $prop) {
        $response->assertJsonPath("props.{$prop}", fn ($value) => is_array($value));
    }
});

test('a partial reload of a held collection sends it anyway', function () {
    actingAs($this->user)->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'categories',
        'X-Inertia-Except-Once-Props' => implode(',', $this->keys),
    ])->assertOk()->assertJsonPath('props.categories.0.id', $this->category->id);
});

test('creating a category through the settings form resends the lists that read it', function () {
    actingAs($this->user)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Except-Once-Props' => implode(',', $this->keys)])
        ->post(route('categories.store'), [
            'name' => 'Brand new',
            'icon' => 'Tag',
            'color' => 'blue',
            'type' => 'expense',
        ])
        ->assertRedirect();

    expect(resentCollections($this->user, $this->keys))->toBe(['categories', 'automationRules']);
});

test('creating a label from a dialog resends the lists that read it', function () {
    actingAs($this->user)
        ->postJson(route('labels.store'), ['name' => 'Fresh label', 'color' => 'blue'])
        ->assertCreated();

    expect(resentCollections($this->user, $this->keys))->toBe(['automationRules', 'labels']);
});

test('each write resends exactly the lists it shows up in', function (Closure $write, array $expected) {
    $write($this);

    expect(resentCollections($this->user, $this->keys))->toBe($expected);
})->with([
    'account created' => [fn ($test) => Account::factory()->create(['user_id' => $test->user->id]), ['accounts']],
    'account renamed' => [fn ($test) => $test->account->update(['name' => 'Renamed']), ['accounts']],
    'account deleted' => [fn ($test) => $test->account->delete(), ['accounts']],
    'account force deleted' => [fn ($test) => $test->account->forceDelete(), ['accounts']],
    'account bank edited' => [function ($test) {
        $bank = Bank::factory()->create(['user_id' => null]);
        $test->account->update(['bank_id' => $bank->id]);
        $test->travel(1)->seconds();
        $test->keys = collectionKeys(inertiaVisit($test->user));
        $test->travel(1)->seconds();
        $bank->update(['name' => 'Renamed bank']);
    }, ['accounts']],
    'property linked to a loan' => [function ($test) {
        $property = Account::factory()->create(['user_id' => $test->user->id, 'type' => AccountType::RealEstate]);
        $detail = RealEstateDetail::factory()->create(['account_id' => $property->id]);
        $test->travel(1)->seconds();
        $test->keys = collectionKeys(inertiaVisit($test->user));
        $test->travel(1)->seconds();
        $detail->update(['linked_loan_account_id' => $test->account->id]);
    }, ['accounts']],
    'own bank created' => [fn ($test) => Bank::factory()->create(['user_id' => $test->user->id]), ['banks']],
    'category renamed' => [fn ($test) => $test->category->update(['name' => 'Renamed']), ['categories', 'automationRules']],
    'category deleted' => [fn ($test) => $test->category->delete(), ['categories', 'automationRules']],
    'category restored' => [function ($test) {
        $test->category->delete();
        $test->travel(1)->seconds();
        $test->keys = collectionKeys(inertiaVisit($test->user));
        $test->travel(1)->seconds();
        $test->category->restore();
    }, ['categories', 'automationRules']],
    'label renamed' => [fn ($test) => $test->label->update(['name' => 'Renamed']), ['automationRules', 'labels']],
    'label deleted' => [fn ($test) => $test->label->delete(), ['automationRules', 'labels']],
    'rule created' => [fn ($test) => AutomationRule::factory()->create(['user_id' => $test->user->id]), ['automationRules']],
    'rule label attached' => [fn ($test) => $test->rule->labels()->sync([$test->label->id]), ['automationRules']],
    'rule label detached' => [function ($test) {
        $test->rule->labels()->sync([$test->label->id]);
        $test->travel(1)->seconds();
        $test->keys = collectionKeys(inertiaVisit($test->user));
        $test->travel(1)->seconds();
        $test->rule->labels()->detach();
    }, ['automationRules']],
    'rule deleted' => [fn ($test) => $test->rule->delete(), ['automationRules']],
]);

test('another user writing leaves the keys alone', function () {
    $other = User::factory()->create();
    Category::factory()->create(['user_id' => $other->id]);
    Label::factory()->create(['user_id' => $other->id]);
    Account::factory()->create(['user_id' => $other->id]);

    expect(resentCollections($this->user, $this->keys))->toBe([]);
});

test('translations are kept by the client per locale', function () {
    $this->user->update(['locale' => 'es']);

    $first = inertiaVisit($this->user->fresh());
    $key = collect($first->json('onceProps'))->search(fn (array $meta): bool => $meta['prop'] === 'translations');

    expect($key)->toStartWith('translations:es:')
        ->and($first->json('props.translations'))->not->toBeEmpty();

    inertiaVisit($this->user->fresh(), [$key])->assertJsonMissingPath('props.translations');

    $this->user->update(['locale' => 'fr']);

    expect(inertiaVisit($this->user->fresh(), [$key])->json('props.translations'))->not->toBeEmpty();
});

test('the version comes from package.json', function () {
    $version = json_decode(file_get_contents(base_path('package.json')))->version;

    inertiaVisit($this->user)->assertJsonPath('props.version', $version);
});
