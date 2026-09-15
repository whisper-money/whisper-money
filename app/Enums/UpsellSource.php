<?php

namespace App\Enums;

/**
 * The upsell entry point a subscription checkout was started from, used to
 * attribute revenue to each upgrade prompt. The value is carried into Stripe as
 * subscription metadata and persisted onto the local subscription so revenue
 * can be measured per upsell point.
 *
 * Mirrored on the frontend by two unions, each covering the points its own
 * screens use: UpsellSource in components/subscription/upgrade-dialog.tsx for
 * the in-app dialog, and OfferSource in components/subscription/
 * subscription-offer.tsx for the onboarding gates. Keep the matching one in
 * sync when adding a point (an unknown value is silently dropped by tryFrom()).
 */
enum UpsellSource: string
{
    case AiCategorization = 'ai_categorization';
    case Connections = 'connections';
    case Accounts = 'accounts';
    case OnboardingBank = 'onboarding_bank';
    case OnboardingAi = 'onboarding_ai';

    /**
     * Where a checkout started from this point sends the user once the plan is
     * active, as query parameters for the onboarding route. The two gates
     * interrupt a flow the user is in the middle of, so they go back to the step
     * that sent them — with the bank picker reopened, since paying for the
     * connection was the whole point of the detour. Every other point is reached
     * from the app itself and has somewhere of its own to return to.
     *
     * @return array<string, string>|null
     */
    public function onboardingReturn(): ?array
    {
        return match ($this) {
            self::OnboardingBank => ['step' => 'create-account', 'connect' => 'bank'],
            self::OnboardingAi => ['step' => 'ai-suggestions'],
            default => null,
        };
    }

    /**
     * Whether the screen behind this point discloses, in a row the user cannot
     * miss, what the AI sorting sends out — and so is allowed to switch the
     * consent on with the plan instead of asking for it again a step later. Only
     * the two onboarding gates carry that row; every other checkout leaves the
     * consent to the screen that asks for it on its own terms.
     */
    public function grantsAiConsent(): bool
    {
        return match ($this) {
            self::OnboardingBank, self::OnboardingAi => true,
            default => false,
        };
    }
}
