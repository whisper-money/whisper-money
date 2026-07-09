<?php

namespace App\Models;

use App\Enums\AnalysisMode;
use App\Models\Concerns\BelongsToSpace;
use Database\Factories\SavedFilterFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedFilter extends Model
{
    /** @use HasFactory<SavedFilterFactory> */
    use BelongsToSpace, HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'space_id',
        'name',
        'filters',
        'analysis_days',
        'analysis_mode',
    ];

    /** @var list<string> */
    protected $hidden = [
        'user_id',
        'space_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'analysis_days' => 'integer',
            'analysis_mode' => AnalysisMode::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
