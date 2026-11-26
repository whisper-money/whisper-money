<?php

namespace App\Services\Forecast\Data;

/**
 * What the forecast is worked out from: the projector's input, plus the
 * per-category estimates behind its monthly ones, which the page shows as
 * rows.
 *
 * The forecast page of a later phase reads it; until then only the tests do.
 *
 * @api
 */
final readonly class ForecastPlan
{
    /**
     * @param  list<CategoryEstimate>  $categoryEstimates
     */
    public function __construct(
        public ProjectionInput $input,
        public array $categoryEstimates,
    ) {}
}
