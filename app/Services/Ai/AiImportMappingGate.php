<?php

namespace App\Services\Ai;

use App\Models\User;

/**
 * The eligibility gate for AI column-mapping suggestions on imports: the config
 * kill switch, a TypeSafe AI key, and either a pro plan or free users let in.
 * No AI consent is needed, since only column headers and shapes are sent.
 */
class AiImportMappingGate
{
    public function allows(User $user): bool
    {
        return (bool) config('ai_import_mapping.enabled')
            && (bool) config('services.typesafe.enabled')
            && ((bool) config('ai_import_mapping.free_users_enabled') || $user->hasProPlan());
    }
}
