<?php

use App\Enums\LeadCohort;
use App\Mail\UserLeadInvitation;
use App\Models\UserLead;

dataset('cohorts', [
    [LeadCohort::Founder, 'free, forever'],
    [LeadCohort::FounderReferrer, 'free, forever'],
    [LeadCohort::EarlyBird, '60 days free'],
    [LeadCohort::Waitlist, '60 days free'],
]);

it('renders the right template per cohort', function (LeadCohort $cohort, string $expected): void {
    $lead = UserLead::factory()->ranked(1)->create([
        'promo_code_monthly' => 'WM-TEST-MMM',
        'promo_code_yearly' => 'WM-TEST-YYY',
        'locale' => 'en',
    ]);

    $rendered = (new UserLeadInvitation($lead, $cohort))->render();

    expect($rendered)->toContain($expected);
})->with('cohorts');

it('includes a signed signup URL bound to the lead', function (): void {
    $lead = UserLead::factory()->ranked(1)->create([
        'promo_code_monthly' => 'WM-TEST',
        'promo_code_yearly' => 'WM-TEST',
        'locale' => 'en',
    ]);

    $rendered = (new UserLeadInvitation($lead, LeadCohort::Founder))->render();

    expect($rendered)->toContain('lead='.$lead->id);
    expect($rendered)->toContain('signup=1');
    expect($rendered)->toContain('signature=');
});

it('renders Spanish copy for Spanish leads', function (): void {
    $lead = UserLead::factory()->ranked(1)->create([
        'promo_code_monthly' => 'WM-TEST',
        'promo_code_yearly' => 'WM-TEST',
        'locale' => 'es',
    ]);

    $rendered = (new UserLeadInvitation($lead, LeadCohort::Founder))->render();

    expect($rendered)->toContain('gratis para siempre');
});
