<?php

use App\Enums\IntegrationRequestStatus;
use App\Models\IntegrationRequest;
use App\Models\User;

// With subscriptions enabled, a freshly created user is on the free plan
// (three monthly actions). Subscribed users get the pro quota of nine.
beforeEach(function () {
    config(['subscriptions.enabled' => true]);
});

test('guests cannot access the integration requests page', function () {
    $this->get('/integration-requests')->assertRedirect();
});

test('the integration requests url renders the dashboard with the drawer open', function () {
    // The dashboard route is paywalled; keep this user out of the paywall.
    config(['subscriptions.enabled' => false]);
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->get('/integration-requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('openIntegrationRequests', true)
        );
});

test('the data endpoint returns the board state as json', function () {
    $user = User::factory()->create();
    IntegrationRequest::factory()->approved()->create();

    $this->actingAs($user)
        ->getJson('/integration-requests/data')
        ->assertOk()
        ->assertJsonCount(1, 'requests')
        ->assertJsonPath('actionsRemaining', 3);
});

test('creating a request auto-votes it and costs two actions', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/integration-requests', [
            'name' => 'Revolut',
            'url' => 'https://revolut.com',
        ])
        ->assertCreated()
        ->assertJsonPath('actionsRemaining', 1)
        ->assertJsonPath('requests.0.votes_count', 1)
        ->assertJsonPath('requests.0.has_voted', true);

    $this->assertDatabaseHas('integration_requests', [
        'user_id' => $user->id,
        'name' => 'Revolut',
        'status' => 'pending',
    ]);
    $this->assertDatabaseCount('integration_request_votes', 1);
});

test('a user cannot create a request with only one action left', function () {
    $user = User::factory()->create();
    IntegrationRequest::factory()->count(2)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/integration-requests', ['name' => 'X', 'url' => 'https://x.com'])
        ->assertStatus(422);
});

test('requesting an integration requires a name and a valid url', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/integration-requests', ['name' => '', 'url' => 'not-a-url'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'url']);
});

test('a user can vote multiple times on the same request', function () {
    $user = User::factory()->create();
    $request = IntegrationRequest::factory()->approved()->create();

    $this->actingAs($user)
        ->postJson("/integration-requests/{$request->id}/vote")
        ->assertOk()
        ->assertJsonPath('requests.0.votes_count', 1)
        ->assertJsonPath('actionsRemaining', 2);

    $this->actingAs($user)
        ->postJson("/integration-requests/{$request->id}/vote")
        ->assertOk()
        ->assertJsonPath('requests.0.votes_count', 2)
        ->assertJsonPath('actionsRemaining', 1);

    expect($request->votes()->where('user_id', $user->id)->count())->toBe(2);
});

test('a user can remove a vote cast this month and recover the action', function () {
    $user = User::factory()->create();
    $request = IntegrationRequest::factory()->approved()->create();

    $this->actingAs($user)
        ->postJson("/integration-requests/{$request->id}/vote")
        ->assertOk()
        ->assertJsonPath('actionsRemaining', 2);

    $this->actingAs($user)
        ->deleteJson("/integration-requests/{$request->id}/vote")
        ->assertOk()
        ->assertJsonPath('requests.0.votes_count', 0)
        ->assertJsonPath('requests.0.can_unvote', false)
        ->assertJsonPath('actionsRemaining', 3);

    $this->assertDatabaseMissing('integration_request_votes', [
        'integration_request_id' => $request->id,
        'user_id' => $user->id,
    ]);
});

test('a vote cast in a previous month cannot be removed', function () {
    $user = User::factory()->create();
    $request = IntegrationRequest::factory()->approved()->create();

    $vote = $request->votes()->create(['user_id' => $user->id]);
    $vote->forceFill(['created_at' => now()->subMonth()])->saveQuietly();

    $this->actingAs($user)
        ->getJson('/integration-requests/data')
        ->assertJsonPath('requests.0.can_unvote', false);

    $this->actingAs($user)
        ->deleteJson("/integration-requests/{$request->id}/vote")
        ->assertOk()
        ->assertJsonPath('requests.0.votes_count', 1);

    $this->assertDatabaseHas('integration_request_votes', [
        'id' => $vote->id,
    ]);
});

test('pro users get nine monthly actions', function () {
    $user = User::factory()->create();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_pro123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro123',
    ]);

    $this->actingAs($user)
        ->getJson('/integration-requests/data')
        ->assertOk()
        ->assertJsonPath('actionsRemaining', 9);
});

test('a user cannot exceed the monthly action limit', function () {
    $user = User::factory()->create();
    IntegrationRequest::factory()->count(3)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson('/integration-requests', ['name' => 'X', 'url' => 'https://x.com'])
        ->assertStatus(422);

    $other = IntegrationRequest::factory()->approved()->create();

    $this->actingAs($user)
        ->postJson("/integration-requests/{$other->id}/vote")
        ->assertStatus(422);
});

test('the admin bypasses the monthly limit and their requests are auto-approved', function () {
    config(['mail.admin_email' => 'admin@whisper.test']);
    $admin = User::factory()->create(['email' => 'admin@whisper.test']);
    IntegrationRequest::factory()->count(5)->create(['user_id' => $admin->id]);

    $this->actingAs($admin)
        ->postJson('/integration-requests', ['name' => 'Revolut', 'url' => 'https://revolut.com'])
        ->assertCreated()
        ->assertJsonPath('actionsRemaining', 3);

    $this->assertDatabaseHas('integration_requests', [
        'user_id' => $admin->id,
        'name' => 'Revolut',
        'status' => IntegrationRequestStatus::Approved->value,
    ]);

    $other = IntegrationRequest::factory()->approved()->create();

    $this->actingAs($admin)
        ->postJson("/integration-requests/{$other->id}/vote")
        ->assertOk();
});

test('repeated voting stops once the monthly limit is reached', function () {
    $user = User::factory()->create();
    $request = IntegrationRequest::factory()->approved()->create();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($user)
            ->postJson("/integration-requests/{$request->id}/vote")
            ->assertOk();
    }

    $this->actingAs($user)
        ->postJson("/integration-requests/{$request->id}/vote")
        ->assertStatus(422);

    expect($request->votes()->count())->toBe(3);
});

test('pending requests are only visible to their creator', function () {
    $owner = User::factory()->create();
    $viewer = User::factory()->create();
    IntegrationRequest::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($viewer)
        ->getJson('/integration-requests/data')
        ->assertJsonCount(0, 'requests');

    $this->actingAs($owner)
        ->getJson('/integration-requests/data')
        ->assertJsonCount(1, 'requests');
});

test('approved requests are visible to every user', function () {
    IntegrationRequest::factory()->approved()->create();

    $this->actingAs(User::factory()->create())
        ->getJson('/integration-requests/data')
        ->assertJsonCount(1, 'requests');
});

test('a user cannot vote on a pending request they do not own', function () {
    $request = IntegrationRequest::factory()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/integration-requests/{$request->id}/vote")
        ->assertNotFound();
});

test('the review command approves a pending request', function () {
    $request = IntegrationRequest::factory()->create(['name' => 'Revolut']);

    $this->artisan('integration-requests:review')
        ->expectsChoice(
            "Review \"{$request->name}\" ({$request->url})",
            'approve',
            ['approve', 'in progress', 'reject', 'not doable', 'skip'],
        )
        ->assertSuccessful();

    expect($request->fresh()->status)->toBe(IntegrationRequestStatus::Approved);
});

test('the review command rejects a pending request', function () {
    $request = IntegrationRequest::factory()->create(['name' => 'Spam']);

    $this->artisan('integration-requests:review')
        ->expectsChoice(
            "Review \"{$request->name}\" ({$request->url})",
            'reject',
            ['approve', 'in progress', 'reject', 'not doable', 'skip'],
        )
        ->assertSuccessful();

    expect($request->fresh()->status)->toBe(IntegrationRequestStatus::Rejected);
});

test('the review command reports when there is nothing to review', function () {
    $this->artisan('integration-requests:review')
        ->expectsOutput('No pending integration requests.')
        ->assertSuccessful();
});

test('not doable requests are visible to everyone with their comment', function () {
    IntegrationRequest::factory()->notDoable()->create([
        'name' => 'Impossible Bank',
        'comment' => 'They have no public API.',
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson('/integration-requests/data')
        ->assertOk()
        ->assertJsonCount(1, 'requests')
        ->assertJsonPath('requests.0.status', 'not_doable')
        ->assertJsonPath('requests.0.comment', 'They have no public API.');
});

test('not doable requests are listed after the rest', function () {
    IntegrationRequest::factory()->notDoable()->create(['name' => 'Not doable']);
    $approved = IntegrationRequest::factory()->approved()->create(['name' => 'Approved']);

    $this->actingAs(User::factory()->create())
        ->getJson('/integration-requests/data')
        ->assertOk()
        ->assertJsonPath('requests.0.id', $approved->id)
        ->assertJsonPath('requests.1.name', 'Not doable');
});

test('rejected requests never appear in the list', function () {
    IntegrationRequest::factory()->rejected()->create();

    $this->actingAs(User::factory()->create())
        ->getJson('/integration-requests/data')
        ->assertOk()
        ->assertJsonCount(0, 'requests');
});

test('nobody can vote on a not doable request', function () {
    $request = IntegrationRequest::factory()->notDoable()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/integration-requests/{$request->id}/vote")
        ->assertNotFound();

    $owner = User::factory()->create();
    $ownRequest = IntegrationRequest::factory()->notDoable()->create(['user_id' => $owner->id]);

    $this->actingAs($owner)
        ->postJson("/integration-requests/{$ownRequest->id}/vote")
        ->assertNotFound();
});

test('a vote on a request later marked not doable cannot be removed', function () {
    $user = User::factory()->create();
    $request = IntegrationRequest::factory()->approved()->create();
    $request->votes()->create(['user_id' => $user->id]);

    $request->update(['status' => IntegrationRequestStatus::NotDoable]);

    $this->actingAs($user)
        ->deleteJson("/integration-requests/{$request->id}/vote")
        ->assertNotFound();

    expect($request->votes()->count())->toBe(1);
});

test('the review command marks a request as not doable with a comment', function () {
    $request = IntegrationRequest::factory()->create(['name' => 'Hard Bank']);

    $this->artisan('integration-requests:review')
        ->expectsChoice(
            "Review \"{$request->name}\" ({$request->url})",
            'not doable',
            ['approve', 'in progress', 'reject', 'not doable', 'skip'],
        )
        ->expectsQuestion('Why is this integration not doable? (shown to users)', 'No public API available.')
        ->assertSuccessful();

    $request->refresh();
    expect($request->status)->toBe(IntegrationRequestStatus::NotDoable);
    expect($request->comment)->toBe('No public API available.');
});

test('the review command with --all moves any request to a new status with a comment', function () {
    $request = IntegrationRequest::factory()->approved()->create(['name' => 'Revolut']);

    $this->artisan('integration-requests:review --all')
        ->expectsQuestion('Which request do you want to update? (number)', '1')
        ->expectsChoice(
            'New status for "Revolut"',
            'in progress',
            ['approve', 'in progress', 'reject', 'not doable'],
        )
        ->expectsQuestion('Add a comment for this request (optional, shown to users)', 'Building it now.')
        ->assertSuccessful();

    $request->refresh();
    expect($request->status)->toBe(IntegrationRequestStatus::InProgress);
    expect($request->comment)->toBe('Building it now.');
});

test('in progress requests are visible to everyone with their comment', function () {
    IntegrationRequest::factory()->inProgress()->create([
        'name' => 'Soon Bank',
        'comment' => 'Working on it.',
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson('/integration-requests/data')
        ->assertOk()
        ->assertJsonCount(1, 'requests')
        ->assertJsonPath('requests.0.status', 'in_progress')
        ->assertJsonPath('requests.0.comment', 'Working on it.');
});

test('a user can vote on an in progress request', function () {
    $request = IntegrationRequest::factory()->inProgress()->create();

    $this->actingAs(User::factory()->create())
        ->postJson("/integration-requests/{$request->id}/vote")
        ->assertOk();

    $this->assertDatabaseHas('integration_request_votes', [
        'integration_request_id' => $request->id,
    ]);
});
