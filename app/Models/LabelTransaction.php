<?php

namespace App\Models;

use App\Models\Concerns\BumpsDataVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class LabelTransaction extends Pivot
{
    use BumpsDataVersion, HasUuids;

    protected $table = 'label_transaction';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function dataVersionOwner(): ?Model
    {
        return $this->dataVersionOwnerVia('transaction');
    }
}
