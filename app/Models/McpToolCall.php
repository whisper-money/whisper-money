<?php

namespace App\Models;

use Database\Factories\McpToolCallFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single MCP tool invocation, recorded by McpTool once the plan gate passes.
 */
class McpToolCall extends Model
{
    /** @use HasFactory<McpToolCallFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'tool',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
