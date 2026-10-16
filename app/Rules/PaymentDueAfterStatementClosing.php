<?php

namespace App\Rules;

use App\Enums\StatementFrequency;
use App\Models\CreditCardDetail;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use Throwable;

/**
 * A credit card statement is charged once it closes, and not long after:
 * some banks charge it the very day it closes, others give a few days to a
 * few weeks on a monthly card, and a weekly one is charged within the week.
 * Anything past the limit is a typo, and projecting it would leave two
 * statements unpaid at once.
 *
 * What gets checked is what the card ends up with: a field the payload leaves
 * out keeps its stored value, and so does a null frequency. That way a weekly
 * card cannot take a monthly gap by having only its dates edited, nor a card
 * with a monthly gap be switched to weekly on its own. Put it on both
 * `payment_due_date` and `statement_frequency`; it reports once, on the due
 * date when the payload carries one.
 */
class PaymentDueAfterStatementClosing implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private ?CreditCardDetail $stored = null) {}

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
        if ($attribute !== 'payment_due_date' && array_key_exists('payment_due_date', $this->data)) {
            return;
        }

        $closingDate = self::parse($this->sentOrStored('statement_closing_date'));
        $dueDate = self::parse($this->sentOrStored('payment_due_date'));

        if ($closingDate === null || $dueDate === null) {
            return;
        }

        if ($dueDate->lessThan($closingDate)) {
            $fail(__("The payment due date can't be before the statement closing date."));

            return;
        }

        $maxDaysToPay = $this->frequency()->maxDaysToPay();

        if ($closingDate->diffInDays($dueDate) > $maxDaysToPay) {
            $fail(__('The payment due date must be within :days days of the statement closing date.', ['days' => $maxDaysToPay]));
        }
    }

    private function sentOrStored(string $key): mixed
    {
        return array_key_exists($key, $this->data) ? $this->data[$key] : $this->stored?->getAttribute($key);
    }

    private function frequency(): StatementFrequency
    {
        $sent = $this->data['statement_frequency'] ?? null;

        return (is_string($sent) ? StatementFrequency::tryFrom($sent) : null)
            ?? $this->stored->statement_frequency
            ?? StatementFrequency::Monthly;
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonImmutable) {
            return $value;
        }

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
