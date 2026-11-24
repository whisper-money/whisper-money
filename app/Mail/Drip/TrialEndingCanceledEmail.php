<?php

namespace App\Mail\Drip;

use App\Mail\Concerns\LocalisesTrialEnd;
use App\Models\User;
use App\Services\Subscriptions\CanceledTrialExtension;
use App\Services\Subscriptions\UserSetupStats;
use Carbon\CarbonImmutable;

/**
 * The trial-ending warning for a user who already scheduled the cancellation:
 * nothing will be charged, so there is no amount to quote. What it says
 * instead is what stops on the day and what they have built that stays, with
 * one button to keep the plan. No discount: they have not paid anything yet.
 *
 * While the user may still have their trial extended, a second button offers
 * two more weeks with the plan back on. It leads to a page that confirms it
 * (`TrialExtensionController`): the link alone changes nothing.
 */
class TrialEndingCanceledEmail extends DripMail
{
    use LocalisesTrialEnd;

    public function __construct(
        User $user,
        public CarbonImmutable $trialEndsAt,
    ) {
        parent::__construct($user);
    }

    protected function dripSubject(): string
    {
        return __('Your Standard trial ends in 3 days');
    }

    protected function template(): string
    {
        return 'mail.drip.trial-ending-canceled';
    }

    protected function repliesToSender(): bool
    {
        return true;
    }

    /**
     * Counted when the email is rendered rather than when the webhook landed,
     * so the numbers are the ones the reader finds if they open the app.
     *
     * `trialEndsAtLocal`, not `trialEndsAt`: see {@see TrialEndingEmail::contentData()}.
     * Whether the extension is offered is decided here for the same reason.
     *
     * @return array<string, mixed>
     */
    protected function contentData(): array
    {
        return [
            'trialEndsAtLocal' => $this->localTrialEnd(),
            'stats' => app(UserSetupStats::class)->for($this->user),
            'offersTrialExtension' => app(CanceledTrialExtension::class)->subscriptionFor($this->user) !== null,
        ];
    }
}
