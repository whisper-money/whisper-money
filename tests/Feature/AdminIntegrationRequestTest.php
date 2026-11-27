<?php

use App\Enums\IntegrationRequestStatus;
use App\Models\IntegrationRequest;
use App\Models\User;

beforeEach(function () {
    config(['app.admin_email' => 'admin@whisper.test']);
    $this->admin = User::factory()->create(['email' => 'admin@whisper.test']);
});

test('the admin page lists only the pending requests, most voted first', function () {
    IntegrationRequest::factory()->approved()->create();
    $older = IntegrationRequest::factory()->create(['created_at' => now()->subDays(2)]);
    $newer = IntegrationRequest::factory()->create(['created_at' => now()->subDay()]);
    $mostVoted = IntegrationRequest::factory()->create(['created_at' => now()->subDays(3)]);
    $mostVoted->votes()->create(['user_id' => $this->admin->id]);

    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('integrationRequests', 3)
            ->where('integrationRequests.0.id', $mostVoted->id)
            ->where('integrationRequests.0.submitted_by', $mostVoted->user->email)
            ->where('integrationRequests.1.id', $newer->id)
            ->where('integrationRequests.2.id', $older->id)
        );
});

test('the integration requests page lists every request, most voted first', function () {
    $voter = User::factory()->create();
    $approved = IntegrationRequest::factory()->approved()->create();
    $approved->votes()->create(['user_id' => $this->admin->id]);
    $approved->votes()->create(['user_id' => $this->admin->id]);
    $approved->votes()->create(['user_id' => $voter->id]);
    $pending = IntegrationRequest::factory()->create();
    $pending->votes()->create(['user_id' => $voter->id]);
    $rejected = IntegrationRequest::factory()->rejected()->create();

    $this->actingAs($this->admin)
        ->get('/admin/integration-requests')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/integration-requests')
            ->has('integrationRequests', 3)
            ->where('integrationRequests.0.id', $approved->id)
            ->where('integrationRequests.0.votes_count', 3)
            ->where('integrationRequests.0.voters_count', 2)
            ->where('integrationRequests.1.id', $pending->id)
            ->where('integrationRequests.1.voters_count', 1)
            ->where('integrationRequests.2.id', $rejected->id)
            ->where('integrationRequests.2.votes_count', 0)
            ->where('integrationRequests.2.voters_count', 0)
        );
});

test('a non-admin cannot open the integration requests page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/integration-requests')
        ->assertNotFound();
});

test('a request whose author deleted their account still says who sent it', function () {
    $author = User::factory()->create(['email' => 'gone@whisper.test']);
    IntegrationRequest::factory()->for($author)->create();
    $author->delete();

    $this->actingAs($this->admin)
        ->get('/admin')
        ->assertInertia(fn ($page) => $page
            ->where('integrationRequests.0.submitted_by', 'gone@whisper.test')
        );
});

test('the admin approves a pending request', function () {
    $request = IntegrationRequest::factory()->create();

    $this->actingAs($this->admin)
        ->from('/admin')
        ->patch("/admin/integration-requests/{$request->id}", ['status' => 'approved'])
        ->assertRedirect('/admin');

    expect($request->fresh()->status)->toBe(IntegrationRequestStatus::Approved);
});

test('marking a request not doable needs a comment, and keeps it', function () {
    $request = IntegrationRequest::factory()->approved()->create();

    $this->actingAs($this->admin)
        ->patch("/admin/integration-requests/{$request->id}", ['status' => 'not_doable'])
        ->assertInvalid('comment');

    expect($request->fresh()->status)->toBe(IntegrationRequestStatus::Approved);

    $this->actingAs($this->admin)
        ->patch("/admin/integration-requests/{$request->id}", [
            'status' => 'not_doable',
            'comment' => 'The bank has no open banking API.',
        ])
        ->assertValid();

    expect($request->fresh())
        ->status->toBe(IntegrationRequestStatus::NotDoable)
        ->comment->toBe('The bank has no open banking API.');
});

test('an in-progress comment is optional', function () {
    $request = IntegrationRequest::factory()->approved()->create();

    $this->actingAs($this->admin)
        ->patch("/admin/integration-requests/{$request->id}", ['status' => 'in_progress'])
        ->assertValid();

    expect($request->fresh())
        ->status->toBe(IntegrationRequestStatus::InProgress)
        ->comment->toBeNull();
});

test('moving to a status that takes no comment drops the stale one', function () {
    $request = IntegrationRequest::factory()->notDoable()->create();

    $this->actingAs($this->admin)
        ->patch("/admin/integration-requests/{$request->id}", [
            'status' => 'done',
            'comment' => 'Ignored',
        ])
        ->assertValid();

    expect($request->fresh())
        ->status->toBe(IntegrationRequestStatus::Done)
        ->comment->toBeNull();
});

test('an unknown status is rejected', function () {
    $request = IntegrationRequest::factory()->create();

    $this->actingAs($this->admin)
        ->patch("/admin/integration-requests/{$request->id}", ['status' => 'shipped'])
        ->assertInvalid('status');

    expect($request->fresh()->status)->toBe(IntegrationRequestStatus::Pending);
});

test('a non-admin cannot change a request status', function () {
    $request = IntegrationRequest::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch("/admin/integration-requests/{$request->id}", ['status' => 'approved'])
        ->assertNotFound();

    expect($request->fresh()->status)->toBe(IntegrationRequestStatus::Pending);
});
