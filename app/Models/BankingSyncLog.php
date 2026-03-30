<?php

namespace App\Models;

use App\Enums\BankingSyncLogStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankingSyncLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'banking_connection_id',
        'status',
        'attempt',
        'error_message',
        'error_class',
        'duration_ms',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => BankingSyncLogStatus::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BankingConnection, $this> */
    public function bankingConnection(): BelongsTo
    {
        return $this->belongsTo(BankingConnection::class);
    }
}
