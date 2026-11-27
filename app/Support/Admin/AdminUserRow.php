<?php

namespace App\Support\Admin;

use App\Models\User;

/**
 * A user as the admin pages list them.
 */
class AdminUserRow
{
    /**
     * @return array{id: string, name: string, email: string, locale: ?string, currency_code: ?string, created_at: ?string, last_active_at: ?string}
     */
    public static function from(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'currency_code' => $user->currency_code,
            'created_at' => $user->created_at?->toIso8601String(),
            'last_active_at' => $user->last_active_at?->toIso8601String(),
        ];
    }
}
