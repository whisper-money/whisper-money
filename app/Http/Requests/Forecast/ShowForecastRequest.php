<?php

namespace App\Http\Requests\Forecast;

use App\Features\Forecast;
use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;

/**
 * The forecast page's query: which account (by default the one with a
 * problem, else the main checking account) and how many months ahead. The
 * page does not exist for a user without the Forecast flag, and only shows the
 * user's own accounts.
 */
class ShowForecastRequest extends FormRequest
{
    private const HORIZONS = [3, 6, 12];

    private const DEFAULT_HORIZON = 12;

    public function authorize(): bool
    {
        abort_unless(Feature::for($this->user())->active(Forecast::class), 404);

        $account = $this->selectedAccount();

        return $account === null || $this->user()->can('view', $account);
    }

    /**
     * An account that is not the user's is refused by authorize(); one that is
     * not in the forecast is the page's 404.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'account' => ['nullable', 'uuid'],
            'horizon' => ['nullable', 'integer', Rule::in(self::HORIZONS)],
        ];
    }

    public function accountId(): ?string
    {
        $account = $this->query('account');

        return is_string($account) && $account !== '' ? $account : null;
    }

    public function horizon(): int
    {
        return (int) ($this->validated('horizon') ?? self::DEFAULT_HORIZON);
    }

    /**
     * The account asked for, when it exists; one that does not is left to the
     * page, which answers it with a 404 like any account it does not show.
     */
    private function selectedAccount(): ?Account
    {
        $id = $this->accountId();

        return $id !== null && preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? Account::query()->find($id) : null;
    }
}
