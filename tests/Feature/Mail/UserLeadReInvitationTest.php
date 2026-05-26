<?php

use App\Mail\UserLeadReInvitation;
use App\Models\UserLead;

it('renders a signed signup URL bound to the lead', function (): void {
    $lead = UserLead::factory()->ranked(1)->create([
        'invitation_sent_at' => now()->subDay(),
        'promo_code_monthly' => 'WM-TEST-M',
        'promo_code_yearly' => 'WM-TEST-Y',
        'locale' => 'en',
    ]);

    $rendered = (new UserLeadReInvitation($lead))->render();

    expect($rendered)->toContain('lead='.$lead->id);
    expect($rendered)->toContain('signup=1');
    expect($rendered)->toContain('signature=');
    expect($rendered)->toContain('WM-TEST-M');
    expect($rendered)->toContain('WM-TEST-Y');
});

it('renders without promo codes', function (): void {
    $lead = UserLead::factory()->ranked(1)->create([
        'invitation_sent_at' => now()->subDay(),
        'promo_code_monthly' => null,
        'promo_code_yearly' => null,
        'locale' => 'en',
    ]);

    $rendered = (new UserLeadReInvitation($lead))->render();

    expect($rendered)->toContain('Still interested in Whisper Money?');
    expect($rendered)->not->toContain('Your launch codes are still available');
});

it('renders Spanish copy for Spanish leads', function (): void {
    $lead = UserLead::factory()->ranked(1)->create([
        'invitation_sent_at' => now()->subDay(),
        'promo_code_monthly' => 'WM-TEST-M',
        'promo_code_yearly' => 'WM-TEST-Y',
        'locale' => 'es',
    ]);

    $mailable = new UserLeadReInvitation($lead);
    $rendered = $mailable->render();

    app()->setLocale('es');

    expect($mailable->envelope()->subject)->toBe('¿Todavía quieres probar Whisper Money?');
    expect($rendered)->toContain('¿Sigues interesado en Whisper Money?');
    expect($rendered)->toContain('Tus códigos de lanzamiento siguen disponibles');
    expect($rendered)->toContain('Crear mi cuenta');
});
