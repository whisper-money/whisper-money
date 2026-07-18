<?php

namespace App\Models;

use App\Enums\ImportConfigType;
use Database\Factories\AccountImportConfigFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountImportConfig extends Model
{
    /** @use HasFactory<AccountImportConfigFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'account_id',
        'type',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'type' => ImportConfigType::class,
            'config' => 'array',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
