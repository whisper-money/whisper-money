<?php

use App\Enums\UpsellSource;

it('sends a checkout started outside the wizard nowhere special and asks for no AI consent', function (UpsellSource $source) {
    expect($source->onboardingReturn())->toBeNull()
        ->and($source->grantsAiConsent())->toBeFalse();
})->with([
    'paywall' => [UpsellSource::Paywall],
    'billing settings' => [UpsellSource::Billing],
    'API settings' => [UpsellSource::Api],
    'MCP settings' => [UpsellSource::Mcp],
]);

it('switches the AI consent on from the AI categorization dialog, which discloses it, without a way back into the wizard', function () {
    expect(UpsellSource::AiCategorization->grantsAiConsent())->toBeTrue()
        ->and(UpsellSource::AiCategorization->onboardingReturn())->toBeNull();
});

it('maps the paywall, billing, API and MCP points to their query values', function () {
    expect(UpsellSource::tryFrom('paywall'))->toBe(UpsellSource::Paywall)
        ->and(UpsellSource::tryFrom('billing'))->toBe(UpsellSource::Billing)
        ->and(UpsellSource::tryFrom('api'))->toBe(UpsellSource::Api)
        ->and(UpsellSource::tryFrom('mcp'))->toBe(UpsellSource::Mcp);
});
