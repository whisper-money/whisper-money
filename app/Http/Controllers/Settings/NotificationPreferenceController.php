<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateNotificationPreferencesRequest;
use Illuminate\Http\RedirectResponse;

class NotificationPreferenceController extends Controller
{
    /**
     * Map of public notification keys to their `user_settings` columns.
     *
     * Add future notification types here to expose them through this endpoint.
     *
     * @var array<string, string>
     */
    public const PREFERENCES = [
        'bank_transactions_synced' => 'notify_on_bank_transactions_synced',
    ];

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
}
