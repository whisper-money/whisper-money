<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class LabelTransaction extends Pivot
{
    use HasUuids;

    protected $table = 'label_transaction';

    public $incrementing = false;

    protected $keyType = 'string';
}
