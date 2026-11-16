<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\RefusesUnknownParameters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An analytics figure read as it stands today, which takes no parameter at
 * all: like the other analytics it adds up every space, so even a `space` is
 * refused rather than ignored.
 */
class AnalyticsSnapshotRequest extends FormRequest
{
    use RefusesUnknownParameters;

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
