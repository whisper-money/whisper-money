<?php

use App\Features\Spaces;
use App\Mail\SpaceInvitationMail;
use App\Models\Account;
use App\Models\SpaceInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;

function ownerWithBusinessSpace(): array
{
    $owner = User::factory()->onboarded()->create();
    Feature::for($owner)->activate(Spaces::class);
    $space = $owner->ownedSpaces()->create(['name' => 'Acme', 'personal' => false]);

    return [$owner, $space];
}

it('sends an invitation and records it as pending', function () {
    Mail::fake();
    [$owner, $space] = ownerWithBusinessSpace();

    $this->actingAs($owner)
        ->post("/spaces/{$space->id}/invitations", ['email' => 'partner@example.com'])
        ->assertRedirect();

    Mail::assertQueued(SpaceInvitationMail::class);
    expect($space->invitations()->where('email', 'partner@example.com')->whereNull('accepted_at')->exists())->toBeTrue();
});

it('enforces the seat cap', function () {
    Mail::fake();
    [$owner, $space] = ownerWithBusinessSpace();

    config(['spaces.max_seats' => 2]); // owner + 1

    $this->actingAs($owner)
        ->post("/spaces/{$space->id}/invitations", ['email' => 'first@example.com'])
        ->assertRedirect();

    // Second invitation would be the 3rd seat (owner + 2) — over the cap of 2.
    $this->actingAs($owner)
        ->post("/spaces/{$space->id}/invitations", ['email' => 'second@example.com'])
        ->assertSessionHas('error');

    expect($space->invitations()->count())->toBe(1);
});

it('rejects inviting someone who cannot be invited', function () {
    Mail::fake();
    [$owner, $space] = ownerWithBusinessSpace();

    $this->actingAs($owner)
        ->post("/spaces/{$space->id}/invitations", ['email' => $owner->email])
        ->assertSessionHas('error');

    expect($space->invitations()->count())->toBe(0);
});

it('only lets the space owner invite', function () {
    [$owner, $space] = ownerWithBusinessSpace();
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->post("/spaces/{$space->id}/invitations", ['email' => 'x@example.com'])
        ->assertForbidden();
});

it('lets an invitee with a matching email accept and join', function () {
    [$owner, $space] = ownerWithBusinessSpace();
    $invitee = User::factory()->create(['email' => 'partner@example.com']);

    $invitation = SpaceInvitation::create([
        'space_id' => $space->id,
        'invited_by_id' => $owner->id,
        'email' => 'partner@example.com',
        'role' => 'member',
        'token' => 'tok_valid',
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($invitee)->get('/spaces/invitations/tok_valid/accept')
        ->assertRedirect(route('dashboard'));

    expect($space->fresh()->hasMember($invitee))->toBeTrue()
        ->and($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($invitee->fresh()->current_space_id)->toBe($space->id);
});

it('refuses to accept an invitation for a different email', function () {
    [$owner, $space] = ownerWithBusinessSpace();
    $other = User::factory()->create(['email' => 'someone-else@example.com']);

    SpaceInvitation::create([
        'space_id' => $space->id,
        'email' => 'partner@example.com',
        'role' => 'member',
        'token' => 'tok_mismatch',
        'expires_at' => now()->addDays(7),
    ]);

    $this->actingAs($other)->get('/spaces/invitations/tok_mismatch/accept')
        ->assertRedirect(route('dashboard'));

    expect($space->fresh()->hasMember($other))->toBeFalse();
});

it('rejects an expired invitation token', function () {
    [$owner, $space] = ownerWithBusinessSpace();
    $invitee = User::factory()->create(['email' => 'late@example.com']);

    SpaceInvitation::create([
        'space_id' => $space->id,
        'email' => 'late@example.com',
        'role' => 'member',
        'token' => 'tok_expired',
        'expires_at' => now()->subDay(),
    ]);

    $this->actingAs($invitee)->get('/spaces/invitations/tok_expired/accept')
        ->assertRedirect(route('dashboard'));

    expect($space->fresh()->hasMember($invitee))->toBeFalse();
});

it('lets a member see the owner\'s space data once they join', function () {
    [$owner, $space] = ownerWithBusinessSpace();
    Account::factory()->for($owner)->create(['space_id' => $space->id, 'name' => 'Acme Bank']);

    $member = User::factory()->onboarded()->create();
    $space->members()->attach($member->id, ['role' => 'member']);
    $member->forceFill(['current_space_id' => $space->id])->save();

    $this->actingAs($member)->get('/accounts')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('accounts', 1)
            ->where('accounts.0.name', 'Acme Bank'));
});

it('removes a member and lets a member leave', function () {
    [$owner, $space] = ownerWithBusinessSpace();
    $member = User::factory()->create();
    $space->members()->attach($member->id, ['role' => 'member']);

    // Owner removes the member.
    $this->actingAs($owner)->delete("/spaces/{$space->id}/members/{$member->id}")
        ->assertRedirect();
    expect($space->fresh()->hasMember($member))->toBeFalse();

    // A member can leave on their own.
    $space->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member)->post("/spaces/{$space->id}/leave")
        ->assertRedirect();
    expect($space->fresh()->hasMember($member))->toBeFalse();
});

it('counts owner, members and pending invitations as seats', function () {
    [$owner, $space] = ownerWithBusinessSpace();
    $member = User::factory()->create();
    $space->members()->attach($member->id, ['role' => 'member']);
    SpaceInvitation::create([
        'space_id' => $space->id,
        'email' => 'pending@example.com',
        'role' => 'member',
        'token' => 'tok_pending',
        'expires_at' => now()->addDays(7),
    ]);

    // owner + 1 member + 1 pending invitation = 3
    expect($owner->seatsInUse())->toBe(3);
});
