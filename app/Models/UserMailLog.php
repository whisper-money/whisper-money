<?php

namespace App\Models;

use App\Enums\DripEmailType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserMailLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'email_type',
        'email_identifier',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'email_type' => DripEmailType::class,
            'email_identifier' => 'string',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
