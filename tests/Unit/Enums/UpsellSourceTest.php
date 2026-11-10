<?php

use App\Enums\UpsellSource;

it('sends a checkout started outside the wizard nowhere special and asks for no AI consent', function (UpsellSource $source) {
    expect($source->onboardingReturn())->toBeNull()
        ->and($source->grantsAiConsent())->toBeFalse();
})->with([
    'paywall' => [UpsellSource::Paywall],
    'billing settings' => [UpsellSource::Billing],
]);

it('maps the paywall and billing points to their query values', function () {
    expect(UpsellSource::tryFrom('paywall'))->toBe(UpsellSource::Paywall)
        ->and(UpsellSource::tryFrom('billing'))->toBe(UpsellSource::Billing);
});
