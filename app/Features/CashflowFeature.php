<?php

namespace App\Features;

use App\Models\User;

class CashflowFeature
{
    public function resolve(User $user): bool
    {
        return false;
    }
}
