<?php

namespace App\Http\Requests\Forecast\Concerns;

use App\Models\Account;
use App\Models\ForecastItem;
use App\Services\Forecast\ForecastAccounts;
use App\Services\Forecast\ForecastEditWindow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * What every forecast edit checks: the row is the user's and lives in the
 * space they are in (anything else reads as a row that does not exist), the
 * accounts named are ones the forecast includes, and the months touched are
 * ones the user may change.
 */
trait ScopesForecastEdits
{
    /**
     * The largest amount an edit takes, in minor units: far beyond any real
     * balance, well inside the columns.
     */
    protected const MAX_AMOUNT = 100_000_000_000_000;

    protected function abortUnlessOwned(Model $model, ?string $spaceId): void
    {
        abort_if($this->user()->cannot('update', $model) || $spaceId !== $this->user()->activeSpace()->id, 404);
    }

    protected function abortUnlessEditableItem(ForecastItem $item): void
    {
        $this->abortUnlessOwned($item, $item->space_id);
        abort_if($item->isArchived(), 404);
    }

    protected function abortUnlessEditableAccount(Account $account): void
    {
        $this->abortUnlessOwned($account, $account->space_id);
        abort_if($account->isArchived(), 404);
    }

    protected function includedAccount(): In
    {
        return Rule::in(app(ForecastAccounts::class)->included($this->user(), $this->user()->activeSpace()->id)->modelKeys());
    }

    protected function editWindow(): ForecastEditWindow
    {
        return app(ForecastEditWindow::class);
    }
}
