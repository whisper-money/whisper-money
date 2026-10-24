<?php

namespace App\Models;

use App\Models\Concerns\BumpsDataVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;

class LabelTransaction extends Pivot
{
    use BumpsDataVersion, HasUuids;

    protected $table = 'label_transaction';

    public $incrementing = false;

    protected $keyType = 'string';

    public function dataVersionOwner(): ?Model
    {
        return Transaction::withTrashed()->find($this->getAttribute('transaction_id'), ['id', 'user_id', 'space_id']);
    }
}
