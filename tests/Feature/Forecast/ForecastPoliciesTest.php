<?php

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
use Illuminate\Database\Eloquent\Model;

/**
 * Every forecast model, with how to make one owned by a given user.
 *
 * @return array<string, array{0: Closure(User): Model}>
 */
function ownedForecastRows(): array
{
    $item = fn (User $owner): ForecastItem => ForecastItem::factory()->create(['user_id' => $owner->id]);

    return [
        'item' => [$item],
        'item version' => [fn (User $owner) => ForecastItemVersion::factory()->for($item($owner))->create()],
        'item override' => [fn (User $owner) => ForecastItemOverride::factory()->for($item($owner))->create()],
        'match' => [fn (User $owner) => ForecastMatch::factory()->for($item($owner))->create()],
        'estimate adjustment' => [fn (User $owner) => ForecastEstimateAdjustment::factory()->create(['user_id' => $owner->id])],
        'dismissal' => [fn (User $owner) => ForecastDismissal::factory()->create(['user_id' => $owner->id])],
        'snapshot' => [fn (User $owner) => ForecastSnapshot::factory()->create(['user_id' => $owner->id])],
        'alert' => [fn (User $owner) => ForecastAlert::factory()->create(['user_id' => $owner->id])],
        'account setting' => [fn (User $owner) => ForecastAccountSetting::factory()->for(Account::factory()->create(['user_id' => $owner->id]))->create()],
        'excluded transaction' => [fn (User $owner) => ForecastExcludedTransaction::factory()->for(Transaction::factory()->create(['user_id' => $owner->id]))->create()],
    ];
}

it('lets the owner view, update and delete their forecast rows', function (Closure $make) {
    $owner = User::factory()->create();
    $row = $make($owner);

    expect($owner->can('view', $row))->toBeTrue()
        ->and($owner->can('update', $row))->toBeTrue()
        ->and($owner->can('delete', $row))->toBeTrue();
})->with(ownedForecastRows());

it('hides a forecast row from another member of the same shared space', function (Closure $make) {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $space = Space::factory()->create(['owner_id' => $owner->id]);
    $owner->forceFill(['current_space_id' => $space->id])->save();
    $member->forceFill(['current_space_id' => $space->id])->save();
    $row = $make($owner);

    expect($member->can('view', $row))->toBeFalse()
        ->and($member->can('update', $row))->toBeFalse()
        ->and($member->can('delete', $row))->toBeFalse();
})->with(ownedForecastRows());
