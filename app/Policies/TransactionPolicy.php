<?php

namespace App\Policies;

use App\Policies\Concerns\HandlesUserOwnership;

class TransactionPolicy
{
    use HandlesUserOwnership;
}
