<?php

namespace App\Rules;

use App\Enums\StatementFrequency;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Throwable;

/**
 * A credit card statement is charged after it closes, and not long after:
 * banks give a few days to a few weeks on a monthly card, and a weekly one is
 * charged within the week. Anything past the limit is a typo, and projecting
 * it would leave two statements unpaid at once. The limit follows the
 * `statement_frequency` sent alongside, monthly when there is none.
 */
class PaymentDueAfterStatementClosing implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $closingDate = self::parse($this->data['statement_closing_date'] ?? null);
        $dueDate = self::parse($value);

        if ($closingDate === null || $dueDate === null) {
            return;
        }

        if ($dueDate->lessThanOrEqualTo($closingDate)) {
            $fail(__('The payment due date must be after the statement closing date.'));

            return;
        }

        $maxDaysToPay = $this->frequency()->maxDaysToPay();

        if ($closingDate->diffInDays($dueDate) > $maxDaysToPay) {
            $fail(__('The payment due date must be within :days days of the statement closing date.', ['days' => $maxDaysToPay]));
        }
    }

    private function frequency(): StatementFrequency
    {
        $frequency = $this->data['statement_frequency'] ?? null;

        return is_string($frequency)
            ? StatementFrequency::tryFrom($frequency) ?? StatementFrequency::Monthly
            : StatementFrequency::Monthly;
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
