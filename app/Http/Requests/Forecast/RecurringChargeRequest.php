<?php

namespace App\Http\Requests\Forecast;

use App\Services\Forecast\Data\RecurringCharge;
use App\Services\Forecast\RecurringCharges;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One of the charges the user pays again and again, by its key (see
 * RecurringCharge::$key). The key is looked up among the user's own charges
 * in the space they are in, worked out again now, so another user's charge,
 * or one that stopped, reads as one that does not exist.
 */
class RecurringChargeRequest extends FormRequest
{
    private ?RecurringCharge $charge = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'size:40'],
        ];
    }

    public function key(): string
    {
        return (string) $this->validated('key');
    }

    /**
     * The charge itself, worked out again from the history: a 404 when the
     * user does not pay it (any more) in the space.
     */
    public function charge(): RecurringCharge
    {
        $this->charge ??= app(RecurringCharges::class)->find($this->user(), $this->user()->activeSpace()->id, $this->key());

        abort_if($this->charge === null, 404);

        return $this->charge;
    }
}
