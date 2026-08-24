<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
});

test('first visit exposes a null marker and stores the newest served created_at', function () {
    $newest = Carbon::parse('2026-06-29T10:00:00Z');

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'created_at' => Carbon::parse('2026-06-20T08:00:00Z'),
    ]);
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'created_at' => $newest,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index'));

    $response->assertInertia(fn ($page) => $page->where('lastVisitAt', null));
    expect($this->user->fresh()->transactions_last_visited_at->equalTo($newest))->toBeTrue();
});

test('a later visit sees the previous marker and advances it forward', function () {
    $previousVisit = Carbon::parse('2026-06-25T00:00:00Z');
    $this->user->forceFill(['transactions_last_visited_at' => $previousVisit])->save();

    $newest = Carbon::parse('2026-06-29T10:00:00Z');
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'created_at' => $newest,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index'));

    $response->assertInertia(fn ($page) => $page->where('lastVisitAt', $previousVisit->toISOString()));
    expect($this->user->fresh()->transactions_last_visited_at->equalTo($newest))->toBeTrue();
});

test('the marker never moves backward when nothing newer was served', function () {
    $marker = Carbon::parse('2026-06-29T23:00:00Z');
    $this->user->forceFill(['transactions_last_visited_at' => $marker])->save();

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'created_at' => Carbon::parse('2026-06-20T08:00:00Z'),
    ]);

    actingAs($this->user)->get(route('transactions.index'))->assertSuccessful();

    expect($this->user->fresh()->transactions_last_visited_at->equalTo($marker))->toBeTrue();
});
