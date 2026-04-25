<?php

namespace App\Services;

use App\Console\Commands\EnsureLaunchCouponsCommand;
use App\Enums\LeadCohort;
use App\Models\UserLead;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;
use RuntimeException;
use Stripe\Exception\ApiErrorException;

class LeadPromoCodeAllocator
{
    /**
     * Ensure the lead has the promo codes required by its cohort.
     * Persists the codes on the lead. Idempotent: skips already-set codes.
     */
    public function ensureCodes(UserLead $lead, LeadCohort $cohort): void
    {
        if ($cohort->isFounderTier()) {
            $code = $lead->promo_code_monthly
                ?? $lead->promo_code_yearly
                ?? $this->createPromotionCode(EnsureLaunchCouponsCommand::COUPON_FOUNDER_FOREVER);

            $lead->forceFill([
                'promo_code_monthly' => $code,
                'promo_code_yearly' => $code,
            ])->save();

            return;
        }

        $changed = false;

        if (empty($lead->promo_code_monthly)) {
            $lead->promo_code_monthly = $this->createPromotionCode(EnsureLaunchCouponsCommand::COUPON_EARLYBIRD_MONTHLY);
            $changed = true;
        }

        if (empty($lead->promo_code_yearly)) {
            $lead->promo_code_yearly = $this->createPromotionCode(EnsureLaunchCouponsCommand::COUPON_EARLYBIRD_YEARLY);
            $changed = true;
        }

        if ($changed) {
            $lead->save();
        }
    }

    private function createPromotionCode(string $couponId): string
    {
        $stripe = Cashier::stripe();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $code = 'WM-'.Str::upper(Str::random(10));

            try {
                $promotionCode = $stripe->promotionCodes->create([
                    'coupon' => $couponId,
                    'code' => $code,
                    'max_redemptions' => 1,
                ]);

                return $promotionCode->code;
            } catch (ApiErrorException $exception) {
                if ($attempt < 5 && $this->isDuplicateCodeError($exception)) {
                    continue;
                }

                throw $exception;
            }
        }

        throw new RuntimeException('Unable to allocate a unique promotion code after 5 attempts.');
    }

    private function isDuplicateCodeError(ApiErrorException $exception): bool
    {
        $message = Str::lower($exception->getMessage());

        return str_contains($message, 'code') && str_contains($message, 'exist');
    }
}
