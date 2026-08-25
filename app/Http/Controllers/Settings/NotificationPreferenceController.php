<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateBudgetNotificationPreferencesRequest;
use App\Http\Requests\Settings\UpdateNotificationPreferencesRequest;
use App\Models\Budget;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferenceController extends Controller
{
    use AuthorizesRequests;

    /**
     * Map of public notification keys to their `user_settings` columns.
     *
     * Add future notification types here to expose them through this endpoint.
     *
     * @var array<string, string>
     */
    public const PREFERENCES = [
        'bank_transactions_synced' => 'notify_on_bank_transactions_synced',
        'inactive_no_bank' => 'notify_on_inactive_no_bank',
        'budget_new_transaction' => 'budget_notify_on_new_transaction',
        'budget_close_to_limit' => 'budget_notify_on_close_to_limit',
        'budget_over_limit' => 'budget_notify_on_over_limit',
    ];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $setting = $user->setting;

        return Inertia::render('settings/notifications', [
            'notifyOnBankTransactionsSynced' => $user->wantsBankTransactionsSyncedEmail(),
            'notifyOnInactiveNoBank' => $user->wantsInactiveNoBankEmail(),
            'budgetDefaults' => [
                'notify_on_new_transaction' => (bool) ($setting->budget_notify_on_new_transaction ?? false),
                'notify_on_close_to_limit' => (bool) ($setting->budget_notify_on_close_to_limit ?? true),
                'notify_on_over_limit' => (bool) ($setting->budget_notify_on_over_limit ?? true),
            ],
            // Archived budgets send no notifications, so they have nothing to
            // configure here.
            'budgets' => $user->budgets()
                ->notArchived()
                ->orderBy('name')
                ->get([
                    'id',
                    'name',
                    'notify_on_new_transaction',
                    'notify_on_close_to_limit',
                    'notify_on_over_limit',
                ]),
        ]);
    }

    public function update(UpdateNotificationPreferencesRequest $request): RedirectResponse
    {
        $attributes = collect($request->validated('notifications'))
            ->mapWithKeys(fn ($enabled, string $key): array => [
                self::PREFERENCES[$key] => filter_var($enabled, FILTER_VALIDATE_BOOLEAN),
            ])
            ->all();

        $request->user()->setting()->updateOrCreate(
            ['user_id' => $request->user()->id],
            $attributes,
        );

        return back();
    }

    public function updateBudget(UpdateBudgetNotificationPreferencesRequest $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', $budget);

        $budget->update($request->validated());

        return back();
    }
}
