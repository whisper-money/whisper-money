<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountBalance extends Model
{
    /** @use HasFactory<\Database\Factories\AccountBalanceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'account_id',
        'balance_date',
        'balance',
    ];

    protected function casts(): array
    {
        return [
            'balance_date' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
