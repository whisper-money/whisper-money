<?php

namespace App\Policies;

use App\Models\Import;
use App\Models\User;
use App\Policies\Concerns\HandlesUserOwnership;

class ImportPolicy
{
    use HandlesUserOwnership;

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Import $import): bool
    {
        return $user->id === $import->user_id;
    }
}
