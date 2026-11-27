<?php

namespace App\Http\Requests\Forecast;

use App\Models\Account;
use App\Services\Forecast\ForecastPresenter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The forecast page's query: which account (by default the one with a
 * problem, else the main checking account; `all` for every account
 * together) and how many months ahead. The
 * page is a link people keep, so a stale value falls back rather than bounce:
 * a horizon it does not offer reads as the default, and an account it does
 * not show (another user's included, so their ids cannot be probed) is a 404.
 */
class ShowForecastRequest extends FormRequest
{
    private const HORIZONS = [3, 6, 12];

    private const DEFAULT_HORIZON = 12;

    public function authorize(): bool
    {
        $id = $this->accountId();
        $account = $id === null ? null : Account::query()->find($id);

        abort_if($account !== null && $this->user()->cannot('view', $account), 404);

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'account' => ['nullable', 'string'],
            'horizon' => ['nullable', 'string'],
        ];
    }

    /**
     * The account asked for: an id, ForecastPresenter::ALL_ACCOUNTS, or null.
     */
    public function account(): ?string
    {
        $account = $this->query('account');

        return is_string($account) && $account !== '' ? $account : null;
    }

    /**
     * The id of the one account asked for, if any.
     */
    public function accountId(): ?string
    {
        $account = $this->account();

        return $account === ForecastPresenter::ALL_ACCOUNTS ? null : $account;
    }

    public function horizon(): int
    {
        $horizon = (int) $this->query('horizon');

        return in_array($horizon, self::HORIZONS, true) ? $horizon : self::DEFAULT_HORIZON;
    }
}
