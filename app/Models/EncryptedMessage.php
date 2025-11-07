<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EncryptedMessage extends Model
{
    protected $fillable = [
        'user_id',
        'encrypted_content',
        'iv',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
