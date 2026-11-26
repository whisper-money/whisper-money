<?php

use App\Enums\ForecastFrequency;
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
use App\Models\User;
use App\Models\UserSetting;
use App\Services\Forecast\Data\ExpandableItem;
use App\Services\Forecast\OccurrenceExpander;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

it('creates every forecast model from its factory', function (string $model) {
    expect($model::factory()->create()->exists)->toBeTrue();
})->with([
    ForecastItem::class,
    ForecastItemVersion::class,
    ForecastItemOverride::class,
    ForecastEstimateAdjustment::class,
    ForecastMatch::class,
    ForecastExcludedTransaction::class,
    ForecastDismissal::class,
    ForecastSnapshot::class,
    ForecastAccountSetting::class,
    ForecastAlert::class,
]);

it('stamps a user-owned forecast row with the owner\'s current space', function (string $model) {
    $user = User::factory()->create();

    expect($model::factory()->create(['user_id' => $user->id])->space_id)->toBe($user->current_space_id)
        ->and($user->current_space_id)->not->toBeNull();
})->with([
    ForecastItem::class,
    ForecastEstimateAdjustment::class,
    ForecastDismissal::class,
    ForecastSnapshot::class,
    ForecastAlert::class,
]);

it('keeps a forecast line\'s account on the same user', function () {
    $item = ForecastItem::factory()->transfer()->create();

    expect($item->account->user_id)->toBe($item->user_id)
        ->and($item->destinationAccount->user_id)->toBe($item->user_id)
        ->and($item->isTransfer())->toBeTrue();
});

it('reads a stored line into the expander with its versions and overrides', function () {
    $item = ForecastItem::factory()
        ->frequency(ForecastFrequency::Monthly)
        ->amount(-1000)
        ->create(['anchor_date' => '2027-01-10', 'ends_on' => '2027-04-10']);
    ForecastItemVersion::factory()->for($item)->create(['effective_from' => '2027-03-01', 'amount' => -1500]);
    ForecastItemOverride::factory()->for($item)->skipped()->create(['occurrence_date' => '2027-02-10']);

    $occurrences = (new OccurrenceExpander)->expand([ExpandableItem::fromModel($item->fresh())], '2027-01-01', '2027-12-31');

    expect(array_map(fn ($occurrence): array => [$occurrence->date, $occurrence->amount, $occurrence->accountId], $occurrences))->toBe([
        ['2027-01-10', -1000, $item->account_id],
        ['2027-03-10', -1500, $item->account_id],
        ['2027-04-10', -1500, $item->account_id],
    ]);
});

it('treats a transfer out of the app as spend of its account', function () {
    $item = ForecastItem::factory()->amount(-20000)->create(['destination_external' => true]);

    expect($item->isTransfer())->toBeTrue()
        ->and(ExpandableItem::fromModel($item)->destinationAccountId)->toBeNull();
});

it('allows one override and one version per date of a line', function (string $model, string $column) {
    $item = ForecastItem::factory()->create();
    $model::factory()->for($item)->create([$column => '2027-02-10']);

    expect(fn () => $model::factory()->for($item)->create([$column => '2027-02-10']))->toThrow(QueryException::class);
})->with([
    [ForecastItemOverride::class, 'occurrence_date'],
    [ForecastItemVersion::class, 'effective_from'],
]);

it('pays each transaction into one forecast occurrence at most', function () {
    $match = ForecastMatch::factory()->create();

    expect(fn () => ForecastMatch::factory()->create(['transaction_id' => $match->transaction_id]))->toThrow(QueryException::class);
});

it('keeps one alert per account and month', function () {
    $alert = ForecastAlert::factory()->create();

    expect(fn () => ForecastAlert::factory()->soft()->create([
        'user_id' => $alert->user_id,
        'account_id' => $alert->account_id,
        'month' => $alert->month,
    ]))->toThrow(QueryException::class);
});

it('removes an account\'s forecast rows with it and frees transfers into it', function () {
    $item = ForecastItem::factory()->transfer()->create();
    $destination = $item->destinationAccount;
    ForecastAccountSetting::factory()->for($item->account)->create();

    $item->account->forceDelete();
    $transferIn = ForecastItem::factory()->transfer($destination)->create(['user_id' => $destination->user_id]);
    $destination->forceDelete();

    expect(ForecastItem::withTrashed()->find($item->id))->toBeNull()
        ->and(ForecastAccountSetting::query()->count())->toBe(0)
        ->and($transferIn->fresh()->destination_account_id)->toBeNull();
});

it('defaults the forecast email preference to on', function () {
    $user = User::factory()->create();
    UserSetting::query()->create(['user_id' => $user->id]);

    expect($user->setting()->first()->forecast_notify_email)->toBeTrue();
});

it('adds no route: with nothing to reach, the engine changes nothing for any user', function () {
    $forecastRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'forecast') || str_starts_with((string) $route->getName(), 'forecast'));

    expect($forecastRoutes)->toBeEmpty()
        ->and(Account::factory()->create()->exists)->toBeTrue();
});
