<?php

namespace App\Models;

use App\Enums\TransactionSource;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    /** @use HasFactory<\Database\Factories\TransactionFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'account_id',
        'category_id',
        'description',
        'description_iv',
        'transaction_date',
        'amount',
        'currency_code',
        'notes',
        'notes_iv',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date:Y-m-d',
            'amount' => 'integer',
            'source' => TransactionSource::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class)
            ->using(LabelTransaction::class)
            ->withTimestamps();
    }
}
