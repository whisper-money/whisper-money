<?php

use App\Models\UserLead;
use App\Services\ResendService;
use Resend\Exceptions\ErrorException;

use function Pest\Laravel\artisan;
use function Pest\Laravel\mock;

const TEST_RESEND_LEADS_SEGMENT_ID = 'test-segment-id';

test('resend:sync-leads syncs all user leads to resend', function () {
    config([
        'services.resend.key' => 'test-api-key',
        'services.resend.leads_segment_id' => TEST_RESEND_LEADS_SEGMENT_ID,
    ]);

    UserLead::factory()->count(3)->create();

    $resendService = mock(ResendService::class);
    $resendService->shouldReceive('syncLead')->times(3);

    artisan('resend:sync-leads')
        ->expectsOutputToContain('Syncing 3 user leads to Resend...')
        ->expectsOutputToContain('Synced 3 user leads to Resend.')
        ->assertSuccessful();
});

test('resend:sync-leads only syncs verified user leads to resend', function () {
    config([
        'services.resend.key' => 'test-api-key',
        'services.resend.leads_segment_id' => TEST_RESEND_LEADS_SEGMENT_ID,
    ]);

    UserLead::factory()->count(2)->create();
    UserLead::factory()->unverified()->count(2)->create();

    $resendService = mock(ResendService::class);
    $resendService->shouldReceive('syncLead')->times(2);

    artisan('resend:sync-leads')
        ->expectsOutputToContain('Syncing 2 user leads to Resend...')
        ->expectsOutputToContain('Synced 2 user leads to Resend.')
        ->assertSuccessful();
});

test('resend:sync-leads fails when api key is not configured', function () {
    config([
        'services.resend.key' => null,
        'services.resend.leads_segment_id' => TEST_RESEND_LEADS_SEGMENT_ID,
    ]);

    artisan('resend:sync-leads')
        ->expectsOutputToContain('Resend API key not configured.')
        ->assertFailed();
});

test('resend:sync-leads fails when leads segment id is not configured', function () {
    config([
        'services.resend.key' => 'test-api-key',
        'services.resend.leads_segment_id' => null,
    ]);

    artisan('resend:sync-leads')
        ->expectsOutputToContain('Resend leads segment ID not configured.')
        ->assertFailed();
});

test('resend:sync-leads handles empty user leads', function () {
    config([
        'services.resend.key' => 'test-api-key',
        'services.resend.leads_segment_id' => TEST_RESEND_LEADS_SEGMENT_ID,
    ]);

    artisan('resend:sync-leads')
        ->expectsOutputToContain('No user leads to sync.')
        ->assertSuccessful();
});

test('resend:sync-leads reports failures and continues syncing', function () {
    config([
        'services.resend.key' => 'test-api-key',
        'services.resend.leads_segment_id' => TEST_RESEND_LEADS_SEGMENT_ID,
    ]);

    $firstLead = UserLead::factory()->create(['email' => 'first@example.com']);
    $secondLead = UserLead::factory()->create(['email' => 'second@example.com']);

    $resendService = mock(ResendService::class);
    $resendService->shouldReceive('syncLead')->once()->with(Mockery::on(fn (UserLead $lead) => $lead->is($firstLead)));
    $resendService->shouldReceive('syncLead')
        ->once()
        ->with(Mockery::on(fn (UserLead $lead) => $lead->is($secondLead)))
        ->andThrow(new RuntimeException('Duplicate request failed'));

    artisan('resend:sync-leads')
        ->expectsOutputToContain('Failed to sync second@example.com: Duplicate request failed')
        ->expectsOutputToContain('Synced 1 user leads to Resend.')
        ->expectsOutputToContain('Failed to sync 1 user leads.')
        ->assertFailed();
});

test('syncLead adds an existing resend contact to the leads segment', function () {
    config([
        'services.resend.key' => 'test-api-key',
        'services.resend.leads_segment_id' => TEST_RESEND_LEADS_SEGMENT_ID,
    ]);

    $lead = UserLead::factory()->create(['email' => 'lead@example.com']);

    $segmentsService = Mockery::mock();
    $segmentsService->shouldReceive('add')
        ->once()
        ->with('lead@example.com', TEST_RESEND_LEADS_SEGMENT_ID);

    $contactsService = new class($segmentsService)
    {
        public function __construct(public object $segments) {}

        public function create(array $parameters): never
        {
            throw new ErrorException([
                'message' => 'Contact already exists',
                'name' => 'conflict_error',
                'statusCode' => 409,
            ]);
        }
    };

    $client = new class($contactsService)
    {
        public function __construct(public object $contacts) {}
    };

    $service = new class($client) extends ResendService
    {
        public function __construct(private readonly object $client) {}

        protected function client(string $apiKey): object
        {
            expect($apiKey)->toBe('test-api-key');

            return $this->client;
        }
    };

    $service->syncLead($lead);
});
