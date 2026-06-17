<?php

use App\Enums\IntegrationRequestStatus;
use App\Models\IntegrationRequest;
use App\Models\User;

test('guests cannot access the integration requests page', function () {
    $this->get('/integration-requests')->assertRedirect();
});

test('the integration requests url renders the dashboard with the drawer open', function () {
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

test('a user can vote and then remove the vote', function () {
    $user = User::factory()->create();
    $request = IntegrationRequest::factory()->approved()->create();

    $this->actingAs($user)
        ->postJson("/integration-requests/{$request->id}/vote")
        ->assertOk();

    $this->assertDatabaseHas('integration_request_votes', [
        'integration_request_id' => $request->id,
        'user_id' => $user->id,
    ]);

    $this->actingAs($user)
        ->postJson("/integration-requests/{$request->id}/vote")
        ->assertOk();

    $this->assertDatabaseMissing('integration_request_votes', [
        'integration_request_id' => $request->id,
        'user_id' => $user->id,
    ]);
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

test('removing a vote is allowed even at the monthly limit', function () {
    $user = User::factory()->create();
    $request = IntegrationRequest::factory()->approved()->create();

    IntegrationRequest::factory()->count(2)->create(['user_id' => $user->id]);
    $request->votes()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->postJson("/integration-requests/{$request->id}/vote")
        ->assertOk();

    $this->assertDatabaseMissing('integration_request_votes', [
        'integration_request_id' => $request->id,
        'user_id' => $user->id,
    ]);
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
            ['approve', 'reject', 'not doable', 'skip'],
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
            ['approve', 'reject', 'not doable', 'skip'],
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

test('the review command marks a request as not doable with a comment', function () {
    $request = IntegrationRequest::factory()->create(['name' => 'Hard Bank']);

    $this->artisan('integration-requests:review')
        ->expectsChoice(
            "Review \"{$request->name}\" ({$request->url})",
            'not doable',
            ['approve', 'reject', 'not doable', 'skip'],
        )
        ->expectsQuestion('Why is this integration not doable? (shown to users)', 'No public API available.')
        ->assertSuccessful();

    $request->refresh();
    expect($request->status)->toBe(IntegrationRequestStatus::NotDoable);
    expect($request->comment)->toBe('No public API available.');
});
