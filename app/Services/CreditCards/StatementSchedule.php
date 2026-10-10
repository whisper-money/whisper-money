<?php

namespace App\Services\CreditCards;

use App\Models\CreditCardDetail;
use Carbon\CarbonImmutable;

/**
 * The monthly statement cycles of a credit card, projected from one anchor
 * closing date and its due date.
 *
 * Every cycle is projected from the anchor itself, never from the cycle before
 * it: chaining month by month would let a closing on the 31st fall to the 28th
 * in February and stay there for good.
 */
final readonly class StatementSchedule
{
    public function __construct(
        private CarbonImmutable $closingAnchor,
        private CarbonImmutable $dueAnchor,
    ) {}

    public static function fromDetail(CreditCardDetail $detail): self
    {
        return new self(
            CarbonImmutable::parse($detail->statement_closing_date->toDateString()),
            CarbonImmutable::parse($detail->payment_due_date->toDateString()),
        );
    }

    /**
     * The cycle $offset months away from the anchor one (negative goes back).
     */
    public function cycle(int $offset): StatementCycle
    {
        return new StatementCycle(
            periodFrom: $this->closingDate($offset - 1)->addDay(),
            closingDate: $this->closingDate($offset),
            dueDate: $this->dueAnchor->addMonthsNoOverflow($offset),
        );
    }

    /**
     * The cycle still collecting purchases on $day: the first one whose
     * closing date is not before it.
     */
    public function openCycleOn(CarbonImmutable $day): StatementCycle
    {
        return $this->cycle($this->openOffsetOn($day));
    }

    /**
     * The statement charged next as of $today, and whether it is final.
     *
     * Between a closing and its due date (both ends counted on the due side)
     * the closed statement is what gets charged, and its amount no longer
     * moves. Once that due date has passed, the next charge is the open cycle,
     * whose amount is still a running total.
     *
     * @return array{cycle: StatementCycle, is_final: bool}
     */
    public function nextPaymentOn(CarbonImmutable $today): array
    {
        $openOffset = $this->openOffsetOn($today);
        $closedCycle = $this->cycle($openOffset - 1);

        if ($today->lessThanOrEqualTo($closedCycle->dueDate)) {
            return ['cycle' => $closedCycle, 'is_final' => true];
        }

        return ['cycle' => $this->cycle($openOffset), 'is_final' => false];
    }

    private function closingDate(int $offset): CarbonImmutable
    {
        return $this->closingAnchor->addMonthsNoOverflow($offset);
    }

    private function openOffsetOn(CarbonImmutable $day): int
    {
        $offset = ($day->year - $this->closingAnchor->year) * 12 + $day->month - $this->closingAnchor->month;

        while ($this->closingDate($offset)->lessThan($day)) {
            $offset++;
        }

        while ($this->closingDate($offset - 1)->greaterThanOrEqualTo($day)) {
            $offset--;
        }

        return $offset;
    }
}
