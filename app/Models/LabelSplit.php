<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class LabelSplit extends Pivot
{
    use HasUuids;

    protected $table = 'label_split';

    public $incrementing = false;

    protected $keyType = 'string';
}
