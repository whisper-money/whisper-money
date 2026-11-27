<?php

use App\Features\Forecast;
use App\Models\Account;
use App\Models\ForecastAccountSetting;
use App\Models\ForecastAlert;
use App\Models\ForecastDismissal;
use App\Models\ForecastEstimateAdjustment;
use App\Models\ForecastExcludedTransaction;
use App\Models\ForecastItem;
use App\Models\ForecastItemOverride;
use App\Models\ForecastItemVersion;
use App\Models\ForecastMatch;
use App\Models\ForecastSnapshot;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ForecastAlertRaised;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->travelTo('2026-11-26 12:00:00');

    $this->user = User::factory()->onboarded()->paywallSeen()->create(['currency_code' => 'EUR']);
    Feature::for($this->user)->activate(Forecast::class);
    $this->spaceId = $this->user->activeSpace()->id;
});

/**
 * One row of every forecast table for `$user` in `$spaceId`, a bell row for
 * its alert, and the alert it names.
 */
function seedForecastRows(User $user, string $spaceId): ForecastAlert
{
    $account = Account::factory()->create(['user_id' => $user->id, 'space_id' => $spaceId, 'currency_code' => 'EUR']);
    $item = ForecastItem::factory()->create(['user_id' => $user->id, 'space_id' => $spaceId, 'account_id' => $account->id]);
    $archived = ForecastItem::factory()->create(['user_id' => $user->id, 'space_id' => $spaceId, 'account_id' => $account->id, 'archived_at' => now()]);
    ForecastItem::factory()->create(['user_id' => $user->id, 'space_id' => $spaceId, 'account_id' => $account->id])->delete();

    ForecastItemVersion::factory()->create(['forecast_item_id' => $item->id]);
    ForecastItemVersion::factory()->create(['forecast_item_id' => $archived->id]);
    ForecastItemOverride::factory()->create(['forecast_item_id' => $item->id]);
    ForecastMatch::factory()->create(['forecast_item_id' => $item->id]);
    ForecastExcludedTransaction::factory()->create([
        'transaction_id' => Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id])->id,
    ]);
    ForecastEstimateAdjustment::factory()->create(['user_id' => $user->id, 'space_id' => $spaceId, 'account_id' => $account->id]);
    ForecastDismissal::factory()->create(['user_id' => $user->id, 'space_id' => $spaceId]);
    ForecastSnapshot::factory()->create(['user_id' => $user->id, 'space_id' => $spaceId]);
    ForecastAccountSetting::factory()->create(['account_id' => $account->id, 'minimum_balance' => 10_000]);
    $alert = ForecastAlert::factory()->create(['user_id' => $user->id, 'space_id' => $spaceId, 'account_id' => $account->id]);
    $user->notify(new ForecastAlertRaised('2026-12', sha1($alert->id), [$alert->id]));

    return $alert;
}

/**
 * How many forecast rows `$user` has in `$spaceId`, per table.
 *
 * @return array<string, int>
 */
function forecastRowCounts(User $user, string $spaceId): array
{
    $items = ForecastItem::withTrashed()->select('id')->where('user_id', $user->id)->where('space_id', $spaceId);
    $accounts = Account::query()->select('id')->where('user_id', $user->id)->where('space_id', $spaceId);
    $transactions = Transaction::query()->select('id')->where('user_id', $user->id)->where('space_id', $spaceId);
    $alerts = ForecastAlert::query()->where('user_id', $user->id)->where('space_id', $spaceId);

    return [
        'items' => ForecastItem::withTrashed()->where('user_id', $user->id)->where('space_id', $spaceId)->count(),
        'versions' => ForecastItemVersion::query()->whereIn('forecast_item_id', $items)->count(),
        'overrides' => ForecastItemOverride::query()->whereIn('forecast_item_id', $items)->count(),
        'matches' => ForecastMatch::query()->whereIn('forecast_item_id', $items)->count(),
        'excluded' => ForecastExcludedTransaction::query()->whereIn('transaction_id', $transactions)->count(),
        'adjustments' => ForecastEstimateAdjustment::query()->where('user_id', $user->id)->where('space_id', $spaceId)->count(),
        'dismissals' => ForecastDismissal::query()->where('user_id', $user->id)->where('space_id', $spaceId)->count(),
        'snapshots' => ForecastSnapshot::query()->where('user_id', $user->id)->where('space_id', $spaceId)->count(),
        'settings' => ForecastAccountSetting::query()->whereIn('account_id', $accounts)->count(),
        'alerts' => (clone $alerts)->count(),
        'notifications' => $user->notifications()->where('type', ForecastAlertRaised::class)
            ->get()->filter(fn ($notification): bool => array_intersect($notification->data['alerts'] ?? [], (clone $alerts)->pluck('id')->all()) !== [])->count(),
    ];
}

/**
 * @return array<string, int>
 */
function fullForecast(): array
{
    return ['items' => 3, 'versions' => 2, 'overrides' => 1, 'matches' => 1, 'excluded' => 1, 'adjustments' => 1, 'dismissals' => 1, 'snapshots' => 1, 'settings' => 1, 'alerts' => 1, 'notifications' => 1];
}

it('deletes every forecast row of the user in the space they are in', function () {
    seedForecastRows($this->user, $this->spaceId);
    expect(forecastRowCounts($this->user, $this->spaceId))->toBe(fullForecast());

    $this->actingAs($this->user)->post(route('forecast.start-over'))->assertRedirect(route('forecast.index'));

    expect(forecastRowCounts($this->user, $this->spaceId))->each->toBe(0)
        ->and(ForecastItem::withTrashed()->count())->toBe(0)
        ->and($this->user->notifications()->count())->toBe(0);
});

it('keeps the forecast of another member of the same space', function () {
    $member = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    seedForecastRows($member, $this->spaceId);
    seedForecastRows($this->user, $this->spaceId);

    $this->actingAs($this->user)->post(route('forecast.start-over'))->assertRedirect();

    expect(forecastRowCounts($member, $this->spaceId))->toBe(fullForecast())
        ->and(forecastRowCounts($this->user, $this->spaceId))->each->toBe(0);
});

it('keeps the user\'s forecast in their other spaces', function () {
    $otherSpace = Space::factory()->create(['owner_id' => $this->user->id]);
    seedForecastRows($this->user, $otherSpace->id);
    seedForecastRows($this->user, $this->spaceId);

    $this->actingAs($this->user)->post(route('forecast.start-over'))->assertRedirect();

    expect(forecastRowCounts($this->user, $otherSpace->id))->toBe(fullForecast())
        ->and(forecastRowCounts($this->user, $this->spaceId))->each->toBe(0);
});

it('keeps a bell row that names no alert while the user has alerts in another space', function () {
    $otherSpace = Space::factory()->create(['owner_id' => $this->user->id]);
    seedForecastRows($this->user, $otherSpace->id);
    $this->user->notify(new ForecastAlertRaised('2026-12', 'legacy'));

    $this->actingAs($this->user)->post(route('forecast.start-over'))->assertRedirect();

    expect($this->user->notifications()->where('data->signature', 'legacy')->exists())->toBeTrue();
});

it('deletes a bell row that names no alert when every alert was in this space', function () {
    seedForecastRows($this->user, $this->spaceId);
    $this->user->notify(new ForecastAlertRaised('2026-12', 'legacy'));

    $this->actingAs($this->user)->post(route('forecast.start-over'))->assertRedirect();

    expect($this->user->notifications()->count())->toBe(0);
});

it('keeps a bell row that also names an alert of another space', function () {
    $otherSpace = Space::factory()->create(['owner_id' => $this->user->id]);
    $elsewhere = seedForecastRows($this->user, $otherSpace->id);
    $here = seedForecastRows($this->user, $this->spaceId);
    $this->user->notify(new ForecastAlertRaised('2026-12', 'both', [$here->id, $elsewhere->id]));

    $this->actingAs($this->user)->post(route('forecast.start-over'))->assertRedirect();

    expect($this->user->notifications()->where('data->signature', 'both')->exists())->toBeTrue();
});

it('forgets the count of suggestions waiting for an answer', function () {
    Cache::put("forecast:new-suggestions:{$this->user->id}:{$this->spaceId}", 0, now()->addDay());

    $this->actingAs($this->user)->post(route('forecast.start-over'))->assertRedirect();

    expect(Cache::has("forecast:new-suggestions:{$this->user->id}:{$this->spaceId}"))->toBeFalse();
});

it('opens the review again, as for a new user', function () {
    seedForecastRows($this->user, $this->spaceId);

    $this->actingAs($this->user)->get(route('forecast.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('reviewPrompt.hasLines', true));

    $this->actingAs($this->user)->post(route('forecast.start-over'))->assertRedirect(route('forecast.index'));

    $this->actingAs($this->user)->get(route('forecast.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->component('forecast/index')->where('reviewPrompt.hasLines', false));
});

it('is a 404 without the Forecast flag, and deletes nothing', function () {
    seedForecastRows($this->user, $this->spaceId);
    Feature::for($this->user)->deactivate(Forecast::class);

    $this->actingAs($this->user)->post(route('forecast.start-over'))->assertNotFound();

    expect(forecastRowCounts($this->user, $this->spaceId))->toBe(fullForecast());
});

it('needs a signed-in user', function () {
    $this->post(route('forecast.start-over'))->assertRedirect(route('register'));
});
