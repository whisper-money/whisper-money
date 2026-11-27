<?php

namespace App\Services\Forecast\Data;

use App\Models\ForecastItem;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the forecast is worked out from: the projector's input, plus the
 * per-category estimates behind its monthly ones and the usual transfers
 * between the user's accounts behind its estimated transfer occurrences,
 * which the page shows as rows.
 *
 * The forecast page of a later phase reads it; until then only the tests do.
 *
 * @api
 */
final readonly class ForecastPlan
{
    /**
     * @param  list<CategoryEstimate>  $categoryEstimates
     * @param  Collection<int, ForecastItem>  $items  The lines of the included accounts, versions and overrides loaded.
     * @param  list<TransferEstimate>  $transferEstimates
     */
    public function __construct(
        public ProjectionInput $input,
        public array $categoryEstimates,
        public Collection $items = new Collection,
        public array $transferEstimates = [],
    ) {}

    /**
     * @return list<string>
     */
    public function projectedAccountIds(): array
    {
        return array_map(fn (ProjectionAccount $account): string => $account->id, $this->input->accounts);
    }

    /**
     * What each occurrence is called, by the id it carries: a line's name,
     * or "Payroll → Daily" for an estimated transfer.
     *
     * @return array<string, string>
     */
    public function occurrenceNames(): array
    {
        $names = $this->items->pluck('name', 'id')->all();

        foreach ($this->transferEstimates as $estimate) {
            $names[$estimate->key()] ??= $estimate->label();
        }

        return $names;
    }
}
