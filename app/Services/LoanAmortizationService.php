<?php

namespace App\Services;

use App\Models\AccountBalance;
use App\Models\LoanDetail;
use Carbon\Carbon;

class LoanAmortizationService
{
    /**
     * Calculate the fixed monthly payment for a loan.
     *
     * Uses the standard amortization formula:
     * M = P * [r(1+r)^n] / [(1+r)^n - 1]
     *
     * @param  int  $principalCents  Original loan amount in cents
     * @param  float  $annualRate  Annual interest rate as percentage (e.g. 3.5)
     * @param  int  $termMonths  Total loan term in months
     * @return int Monthly payment in cents
     */
    public function calculateMonthlyPayment(int $principalCents, float $annualRate, int $termMonths): int
    {
        if ($termMonths <= 0) {
            return 0;
        }

        $principal = $principalCents / 100;
        $monthlyRate = ($annualRate / 100) / 12;

        if ($monthlyRate === 0.0) {
            return (int) round(($principal / $termMonths) * 100);
        }

        $factor = pow(1 + $monthlyRate, $termMonths);
        $payment = $principal * ($monthlyRate * $factor) / ($factor - 1);

        return (int) round($payment * 100);
    }

    /**
     * Calculate the remaining balance after a given number of payments.
     *
     * Uses the formula: B(k) = P * [(1+r)^n - (1+r)^k] / [(1+r)^n - 1]
     *
     * @param  int  $principalCents  Original loan amount in cents
     * @param  float  $annualRate  Annual interest rate as percentage (e.g. 3.5)
     * @param  int  $termMonths  Total loan term in months
     * @param  int  $paymentsMade  Number of payments already made
     * @return int Remaining balance in cents
     */
    public function calculateRemainingBalance(int $principalCents, float $annualRate, int $termMonths, int $paymentsMade): int
    {
        if ($paymentsMade >= $termMonths) {
            return 0;
        }

        if ($paymentsMade <= 0) {
            return $principalCents;
        }

        $principal = $principalCents / 100;
        $monthlyRate = ($annualRate / 100) / 12;

        if ($monthlyRate === 0.0) {
            $remaining = $principal - ($principal / $termMonths * $paymentsMade);

            return max(0, (int) round($remaining * 100));
        }

        $factorN = pow(1 + $monthlyRate, $termMonths);
        $factorK = pow(1 + $monthlyRate, $paymentsMade);
        $remaining = $principal * ($factorN - $factorK) / ($factorN - 1);

        return max(0, (int) round($remaining * 100));
    }

    /**
     * Generate a monthly projection from the last known balance point forward.
     *
     * Finds the most recent account_balance entry and projects forward using
     * the loan's current interest rate and remaining term.
     *
     * @param  LoanDetail  $loanDetail  The loan's amortization parameters
     * @param  int  $monthsAhead  How many months into the future to project
     * @return array<string, int> Map of 'Y-m' => balance in cents
     */
    public function generateProjection(LoanDetail $loanDetail, int $monthsAhead = 12): array
    {
        $lastBalance = AccountBalance::query()
            ->where('account_id', $loanDetail->account_id)
            ->orderBy('balance_date', 'desc')
            ->first();

        if ($lastBalance) {
            return $this->projectFromBalance(
                $lastBalance->balance,
                $lastBalance->balance_date,
                $loanDetail->annual_interest_rate,
                $this->calculateRemainingMonths($loanDetail, $lastBalance->balance_date),
                $monthsAhead,
            );
        }

        return $this->projectFromOriginal($loanDetail, $monthsAhead);
    }

    /**
     * Project future balances starting from a known balance point.
     *
     * @param  int  $currentBalanceCents  Current balance in cents
     * @param  Carbon  $fromDate  Date of the known balance
     * @param  float  $annualRate  Annual interest rate as percentage
     * @param  int  $remainingMonths  Remaining payments on the loan
     * @param  int  $monthsAhead  How many months to project forward
     * @return array<string, int> Map of 'Y-m' => balance in cents
     */
    public function projectFromBalance(
        int $currentBalanceCents,
        Carbon $fromDate,
        float $annualRate,
        int $remainingMonths,
        int $monthsAhead,
    ): array {
        $projection = [];

        $monthsToProject = min($monthsAhead, $remainingMonths);

        for ($i = 1; $i <= $monthsToProject; $i++) {
            $date = $fromDate->copy()->addMonthsNoOverflow($i);
            $balance = $this->calculateRemainingBalance(
                $currentBalanceCents,
                $annualRate,
                $remainingMonths,
                $i,
            );

            $projection[$date->format('Y-m')] = $balance;
        }

        return $projection;
    }

    /**
     * Project from the loan's original start date (no balance entries exist).
     *
     * @return array<string, int> Map of 'Y-m' => balance in cents
     */
    private function projectFromOriginal(LoanDetail $loanDetail, int $monthsAhead): array
    {
        $projection = [];
        $now = Carbon::now();
        $start = $loanDetail->start_date->copy()->startOfMonth();

        $monthsElapsed = (int) $start->diffInMonths($now);
        $startMonth = max(0, $monthsElapsed);

        $monthsToProject = min($monthsAhead, $loanDetail->loan_term_months - $startMonth);

        for ($i = 1; $i <= $monthsToProject; $i++) {
            $paymentNumber = $startMonth + $i;
            if ($paymentNumber > $loanDetail->loan_term_months) {
                break;
            }

            $date = $now->copy()->addMonthsNoOverflow($i);
            $balance = $this->calculateRemainingBalance(
                $loanDetail->original_amount,
                $loanDetail->annual_interest_rate,
                $loanDetail->loan_term_months,
                $paymentNumber,
            );

            $projection[$date->format('Y-m')] = $balance;
        }

        return $projection;
    }

    /**
     * Calculate how many months remain on the loan from a given date.
     */
    public function calculateRemainingMonths(LoanDetail $loanDetail, Carbon $fromDate): int
    {
        $monthsElapsed = (int) $loanDetail->start_date->startOfMonth()->diffInMonths($fromDate->copy()->startOfMonth());
        $remaining = $loanDetail->loan_term_months - $monthsElapsed;

        return max(0, $remaining);
    }

    /**
     * Calculate the projected balance at a specific date for a loan.
     *
     * First checks for a real account_balance entry. If one exists,
     * projects forward from that known balance point. Otherwise,
     * calculates from the loan's original amortization schedule.
     */
    public function getBalanceAtDate(LoanDetail $loanDetail, Carbon $date): int
    {
        $latestBalance = AccountBalance::query()
            ->where('account_id', $loanDetail->account_id)
            ->where('balance_date', '<=', $date->toDateString())
            ->orderBy('balance_date', 'desc')
            ->first();

        if ($latestBalance) {
            $monthsBetween = (int) $latestBalance->balance_date->startOfMonth()
                ->diffInMonths($date->copy()->startOfMonth());

            if ($monthsBetween <= 0) {
                return $latestBalance->balance;
            }

            $remainingMonths = $this->calculateRemainingMonths($loanDetail, $latestBalance->balance_date);

            if ($remainingMonths <= 0) {
                return 0;
            }

            return $this->calculateRemainingBalance(
                $latestBalance->balance,
                $loanDetail->annual_interest_rate,
                $remainingMonths,
                $monthsBetween,
            );
        }

        $monthsElapsed = (int) $loanDetail->start_date->startOfMonth()->diffInMonths($date->copy()->startOfMonth());

        if ($monthsElapsed >= $loanDetail->loan_term_months) {
            return 0;
        }

        if ($monthsElapsed <= 0) {
            return $loanDetail->original_amount;
        }

        return $this->calculateRemainingBalance(
            $loanDetail->original_amount,
            $loanDetail->annual_interest_rate,
            $loanDetail->loan_term_months,
            $monthsElapsed,
        );
    }
}
