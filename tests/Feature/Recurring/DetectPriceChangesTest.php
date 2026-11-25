<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Services\Recurring\DetectPriceChanges;
use App\Services\TransactionSplitter;

beforeEach(function () {
    $this->detect = app(DetectPriceChanges::class);
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'space_id' => $this->user->personalSpace->id,
        'type' => AccountType::Checking,
        'currency_code' => 'EUR',
    ]);
});

it('spots a subscription that stepped up and stayed there', function () {
    seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1599, 1599, 1599]);

    $rises = $this->detect->forUser($this->user);

    expect($rises)->toHaveCount(1)
        ->and($rises->first()->previousAmount)->toBe(1299)
        ->and($rises->first()->currentAmount)->toBe(1599)
        ->and($rises->first()->increase())->toBe(300)
        ->and($rises->first()->percentage())->toBe(23)
        // A monthly rise of 3.00 costs 36.00 more a year.
        ->and($rises->first()->yearlyImpact())->toBe(3600);
});

it('still reads a charge the user split since, at its full amount', function () {
    $series = seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1599, 1599, 1599]);
    $latest = $series->transactions()->orderByDesc('transaction_date')->first();
    app(TransactionSplitter::class)->split($latest, [['amount' => -1000], ['amount' => -599]]);

    $rises = $this->detect->forUser($this->user);

    expect($series->fresh()->transactions)->toHaveCount(6)
        ->and($rises)->toHaveCount(1)
        ->and($rises->first()->previousAmount)->toBe(1299)
        ->and($rises->first()->currentAmount)->toBe(1599);
});

it('drops a charge the user deleted from the history', function () {
    $series = seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1599, 1599, 1599]);
    $series->transactions()->orderByDesc('transaction_date')->first()->delete();

    expect($series->fresh()->transactions)->toHaveCount(5)
        ->and($this->detect->forUser($this->user))->toHaveCount(0);
});

it('leaves a bill that simply swings alone', function () {
    // The electricity case: no price moved, it is just weather. Both windows
    // are far too spread out to claim a price held still and then changed.
    seriesCharging($this->user, $this->account, [4210, 8875, 6130, 11940, 5320, 9410]);

    expect($this->detect->forUser($this->user))->toBeEmpty();
});

it('ignores a single expensive month', function () {
    // One surcharge, then back to normal: the recent window is not steady.
    seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1299, 4999, 1299]);

    expect($this->detect->forUser($this->user))->toBeEmpty();
});

it('ignores a rise too small to care about', function () {
    // 4% is under the threshold even though every charge is rock steady.
    seriesCharging($this->user, $this->account, [5000, 5000, 5000, 5200, 5200, 5200]);

    expect($this->detect->forUser($this->user))->toBeEmpty();
});

it('ignores a few cents on a tiny charge', function () {
    // 20% but only 10 cents: relative alone would make this shout.
    seriesCharging($this->user, $this->account, [50, 50, 50, 60, 60, 60]);

    expect($this->detect->forUser($this->user))->toBeEmpty();
});

it('says nothing about a price that came down', function () {
    seriesCharging($this->user, $this->account, [1599, 1599, 1599, 1299, 1299, 1299]);

    expect($this->detect->forUser($this->user))->toBeEmpty();
});

it('waits until there is enough history on both sides', function () {
    seriesCharging($this->user, $this->account, [1299, 1299, 1599, 1599, 1599]);

    expect($this->detect->forUser($this->user))->toBeEmpty();
});

it('stays quiet about a rise it already announced', function () {
    $series = seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1599, 1599, 1599]);
    $series->forceFill(['price_alerted_amount' => 1599])->saveQuietly();

    expect($this->detect->forUser($this->user))->toBeEmpty();
});

it('speaks again when an announced price moves further', function () {
    $series = seriesCharging($this->user, $this->account, [1599, 1599, 1599, 1999, 1999, 1999]);
    $series->forceFill(['price_alerted_amount' => 1599])->saveQuietly();

    expect($this->detect->forUser($this->user))->toHaveCount(1);
});

it('leaves income alone', function () {
    // A bigger salary is not a cost to warn anyone about.
    $series = seriesCharging($this->user, $this->account, [200000, 200000, 200000, 250000, 250000, 250000], 'Nomina');
    $series->forceFill(['direction' => 'income'])->saveQuietly();

    expect($this->detect->forUser($this->user))->toBeEmpty();
});

it('leaves dismissed and lapsed series alone', function () {
    $ignored = seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1599, 1599, 1599], 'Ignorada');
    $ignored->forceFill(['user_state' => 'ignored'])->saveQuietly();
    $lapsed = seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1599, 1599, 1599], 'Caducada');
    $lapsed->forceFill(['status' => 'lapsed'])->saveQuietly();

    expect($this->detect->forUser($this->user))->toBeEmpty();
});

it('leads with the biggest rise', function () {
    seriesCharging($this->user, $this->account, [1000, 1000, 1000, 1200, 1200, 1200], 'Pequeña');
    seriesCharging($this->user, $this->account, [4000, 4000, 4000, 9000, 9000, 9000], 'Enorme');
    seriesCharging($this->user, $this->account, [2000, 2000, 2000, 2600, 2600, 2600], 'Mediana');

    expect($this->detect->forUser($this->user)->map(fn ($rise): string => $rise->series->display_name)->all())
        ->toBe(['Enorme', 'Mediana', 'Pequeña']);
});
