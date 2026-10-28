<?php

use App\Enums\LabelSource;
use App\Models\Account;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\withHeaders;

/**
 * @return array<string, string>
 */
function labelsWriter(User $user): array
{
    return apiV1Bearer($user, ['api:read', 'api:write']);
}

function sharedLabelSpace(User $member, User $owner): Space
{
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    return $space;
}

describe('list', function () {
    it('lists the labels of the personal space, newest first, goal labels included', function () {
        $user = User::factory()->create();
        $older = Label::factory()->create(['user_id' => $user->id, 'source' => LabelSource::SavingsGoal, 'created_at' => '2026-01-14 09:30:00']);
        $newer = Label::factory()->create(['user_id' => $user->id, 'name' => 'Holidays', 'color' => 'blue', 'created_at' => '2026-01-15 09:30:00']);
        Label::factory()->create();

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/labels')
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$newer->id, $older->id])
            ->assertJsonPath('data.1.source', 'saving_goal')
            ->assertJsonPath('data.0', [
                'id' => $newer->id,
                'space_id' => $user->personalSpace->id,
                'name' => 'Holidays',
                'color' => 'blue',
                'source' => 'user',
                'owned' => true,
                'created_at' => '2026-01-15T09:30:00+00:00',
            ]);
    });

    it('lists a shared space when asked, and refuses one the user cannot access', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedLabelSpace($user, $owner);
        $theirs = Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        Label::factory()->create(['user_id' => $user->id]);
        $headers = apiV1Bearer($user);

        withHeaders($headers)
            ->getJson('/api/v1/labels?space='.$space->id)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$theirs->id])
            ->assertJsonPath('data.0.owned', false);

        withHeaders($headers)
            ->getJson('/api/v1/labels?space='.Space::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('space');
    });

    it('pages through the labels with a cursor', function () {
        $user = User::factory()->create();
        $labels = collect(range(1, 3))->map(fn (int $days): Label => Label::factory()->create([
            'user_id' => $user->id,
            'created_at' => now()->subDays($days),
        ]));
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson('/api/v1/labels?limit=2')
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('data.*.id', [$labels[0]->id, $labels[1]->id]);

        withHeaders($headers)->getJson('/api/v1/labels?limit=2&starting_after='.$labels[1]->id)
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$labels[2]->id]);

        withHeaders($headers)->getJson('/api/v1/labels?starting_after='.Label::factory()->create()->id)
            ->assertBadRequest()
            ->assertJsonPath('code', 'invalid_cursor');
    });
});

describe('show', function () {
    it('shows a label of the space, and 404s on someone else\'s', function () {
        $user = User::factory()->create();
        $label = Label::factory()->create(['user_id' => $user->id]);
        $headers = apiV1Bearer($user);

        withHeaders($headers)
            ->getJson('/api/v1/labels/'.$label->id)
            ->assertOk()
            ->assertJsonPath('data.id', $label->id);

        withHeaders($headers)
            ->getJson('/api/v1/labels/'.Label::factory()->create()->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });
});

describe('create', function () {
    it('creates a label in the personal space', function () {
        $user = User::factory()->create();

        withHeaders(labelsWriter($user))
            ->postJson('/api/v1/labels', ['name' => 'Business', 'color' => 'green'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Business')
            ->assertJsonPath('data.color', 'green')
            ->assertJsonPath('data.source', 'user')
            ->assertJsonPath('data.space_id', $user->personalSpace->id);
    });

    it('refuses invalid and unknown fields and a name already taken in the space', function () {
        $user = User::factory()->create();
        Label::factory()->create(['user_id' => $user->id, 'name' => 'Business']);
        $headers = labelsWriter($user);

        withHeaders($headers)
            ->postJson('/api/v1/labels', ['color' => 'mauve', 'colour' => 'red'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'color', 'colour']);

        withHeaders($headers)
            ->postJson('/api/v1/labels', ['name' => 'Business', 'color' => 'green'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'A label with that name already exists.');

        expect($user->labels()->count())->toBe(1);
    });

    it('refuses a read-only token', function () {
        $user = User::factory()->create();

        withHeaders(apiV1Bearer($user))
            ->postJson('/api/v1/labels', ['name' => 'Business', 'color' => 'green'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($user->labels()->count())->toBe(0);
    });

    it('replays a POST with the same idempotency key instead of creating a second label', function () {
        $user = User::factory()->create();
        $headers = [...labelsWriter($user), 'Idempotency-Key' => 'create-business'];
        $body = ['name' => 'Business', 'color' => 'green'];

        $first = withHeaders($headers)->postJson('/api/v1/labels', $body)->assertCreated();
        $replay = withHeaders($headers)->postJson('/api/v1/labels', $body)->assertCreated();

        $replay->assertHeader('Idempotent-Replayed', 'true');
        expect($replay->json('data.id'))->toBe($first->json('data.id'))
            ->and($user->labels()->count())->toBe(1);
    });
});

describe('update', function () {
    it('changes only the fields it is sent, keeping its own name free', function () {
        $user = User::factory()->create();
        $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Business', 'color' => 'green']);
        Label::factory()->create(['user_id' => $user->id, 'name' => 'Work']);
        $headers = labelsWriter($user);

        withHeaders($headers)
            ->patchJson('/api/v1/labels/'.$label->id, ['name' => 'Business', 'color' => 'red'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Business')
            ->assertJsonPath('data.color', 'red');

        withHeaders($headers)
            ->patchJson('/api/v1/labels/'.$label->id, ['name' => 'Work'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'A label with that name already exists.');

        expect($label->fresh()->name)->toBe('Business');
    });

    it('404s on another user\'s label and refuses a read-only token', function () {
        $user = User::factory()->create();
        $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Business']);

        withHeaders(labelsWriter($user))
            ->patchJson('/api/v1/labels/'.Label::factory()->create()->id, ['name' => 'Mine now'])
            ->assertNotFound();

        withHeaders(apiV1Bearer($user))
            ->patchJson('/api/v1/labels/'.$label->id, ['name' => 'Work'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($label->fresh()->name)->toBe('Business');
    });
});

describe('ownership', function () {
    it('lets a member read another member\'s label but not change or delete it', function () {
        $member = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedLabelSpace($member, $owner);
        $theirs = Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Theirs']);
        $headers = labelsWriter($member);

        withHeaders($headers)
            ->getJson('/api/v1/labels/'.$theirs->id.'?space='.$space->id)
            ->assertOk();

        withHeaders($headers)
            ->patchJson('/api/v1/labels/'.$theirs->id.'?space='.$space->id, ['name' => 'Mine'])
            ->assertForbidden()
            ->assertJsonPath('code', 'not_label_owner');

        withHeaders($headers)
            ->deleteJson('/api/v1/labels/'.$theirs->id.'?space='.$space->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'not_label_owner');

        expect($theirs->fresh()->name)->toBe('Theirs');
    });
});

describe('delete', function () {
    it('deletes a label and takes it off its transactions', function () {
        $user = User::factory()->create();
        $label = Label::factory()->create(['user_id' => $user->id]);
        $transaction = Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => Account::factory()->create(['user_id' => $user->id])->id,
        ]);
        $transaction->labels()->attach($label);

        withHeaders(labelsWriter($user))
            ->deleteJson('/api/v1/labels/'.$label->id)
            ->assertOk()
            ->assertExactJson(['id' => $label->id, 'deleted' => true]);

        expect(Label::query()->find($label->id))->toBeNull()
            ->and($transaction->fresh()->labels)->toBeEmpty();
    });

    it('404s on another user\'s label and refuses a read-only token', function () {
        $user = User::factory()->create();
        $label = Label::factory()->create(['user_id' => $user->id]);

        withHeaders(labelsWriter($user))
            ->deleteJson('/api/v1/labels/'.Label::factory()->create()->id)
            ->assertNotFound();

        withHeaders(apiV1Bearer($user))
            ->deleteJson('/api/v1/labels/'.$label->id)
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($label->fresh())->not->toBeNull();
    });
});
