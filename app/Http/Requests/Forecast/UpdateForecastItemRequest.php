<?php

namespace App\Http\Requests\Forecast;

use App\Enums\ForecastFrequency;
use App\Services\Forecast\Data\ExpandableItem;
use App\Services\Forecast\ForecastItemWriter;
use App\Services\Forecast\OccurrenceExpander;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A change to one occurrence of a line ("only this month") or to it and
 * every one after it ("from this month on"). `occurrence_date` is the date
 * the schedule gives the occurrence. The amount comes positive and keeps
 * the line's sign; a new repetition only applies from here on.
 */
class UpdateForecastItemRequest extends ForecastItemActionRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in(['occurrence', 'from_here'])],
            'occurrence_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['nullable', 'integer', 'min:1'],
            'frequency' => ['nullable', 'prohibited_unless:scope,from_here', Rule::enum(ForecastFrequency::class)],
            'day' => ['nullable', 'integer', 'between:1,31'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $date = $this->occurrenceDate();

                if (! $this->isScheduled($date) || ! $this->editWindow()->allowsOccurrence($this->user(), $this->item(), $date)) {
                    $validator->errors()->add('occurrence_date', __('This month can no longer be changed.'));

                    return;
                }

                if ($this->day() !== null && ! $this->editWindow()->allowsNewDate($this->user(), ForecastItemWriter::onDay($date, $this->day()))) {
                    $validator->errors()->add('day', __('Choose a day from today within the months you can plan.'));
                }
            },
        ];
    }

    public function isFromHere(): bool
    {
        return $this->input('scope') === 'from_here';
    }

    public function occurrenceDate(): string
    {
        return (string) $this->input('occurrence_date');
    }

    public function day(): ?int
    {
        return $this->filled('day') ? (int) $this->input('day') : null;
    }

    public function frequency(): ?ForecastFrequency
    {
        return $this->filled('frequency') ? ForecastFrequency::from((string) $this->input('frequency')) : null;
    }

    /**
     * The new amount with the line's sign, or null when it stays.
     */
    public function signedAmount(): ?int
    {
        if (! $this->filled('amount')) {
            return null;
        }

        $isIncome = ($this->item()->versions()->latest('effective_from')->value('amount') ?? 0) > 0;

        return $isIncome ? (int) $this->input('amount') : -(int) $this->input('amount');
    }

    /**
     * The day picked for this occurrence alone, as the date it moves to.
     */
    public function movedTo(): ?string
    {
        return $this->day() === null ? null : ForecastItemWriter::onDay($this->occurrenceDate(), $this->day());
    }

    /**
     * Whether the schedule puts an occurrence on the date. Overrides are left
     * out, so the occurrence is found on its own date even once moved.
     */
    private function isScheduled(string $date): bool
    {
        $line = ExpandableItem::fromModel($this->item());
        $schedule = new ExpandableItem($line->id, $line->accountId, $line->destinationAccountId, $line->frequency, $line->anchorDate, $line->endsOn, $line->versions);

        return app(OccurrenceExpander::class)->expand([$schedule], $date, $date) !== [];
    }
}
