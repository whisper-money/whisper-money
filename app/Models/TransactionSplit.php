<?php

namespace App\Models;

use Database\Factories\TransactionSplitFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TransactionSplit extends Model
{
    /** @use HasFactory<TransactionSplitFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'transaction_id',
        'category_id',
        'amount',
    ];

    /**
     * Hide the pivot so a Label loaded through this line's labels() looks
     * identical to one loaded elsewhere.
     *
     * @var list<string>
     */
    protected $hidden = [
        'pivot',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsToMany<Label, $this, LabelSplit, 'pivot'> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'label_split')
            ->using(LabelSplit::class)
            ->withTimestamps();
    }
}
