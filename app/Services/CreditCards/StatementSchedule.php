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

    /**
     * The schedule a card's statement dates project, or null while it has
     * none (a card can carry just a credit limit).
     */
    public static function fromDetail(?CreditCardDetail $detail): ?self
    {
        $closingDate = $detail?->statement_closing_date;
        $dueDate = $detail?->payment_due_date;

        if ($closingDate === null || $dueDate === null) {
            return null;
        }

        return new self($closingDate, $dueDate);
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
     * A closed statement whose due date has not passed yet (the due date
     * itself still counts) is what gets charged, and its amount no longer
     * moves. With a due date more than a cycle after its closing, two closed
     * statements can be pending at once, and the older one is charged first.
     * Once every closed statement is past due, the next charge is the open
     * cycle, whose amount is still a running total.
     *
     * @return array{cycle: StatementCycle, is_final: bool}
     */
    public function nextPaymentOn(CarbonImmutable $today): array
    {
        $openOffset = $this->openOffsetOn($today);
        $offset = $openOffset;

        while ($today->lessThanOrEqualTo($this->cycle($offset - 1)->dueDate)) {
            $offset--;
        }

        return ['cycle' => $this->cycle($offset), 'is_final' => $offset < $openOffset];
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
