<?php

namespace App\Models;

use App\Enums\MonthlySummaryCard;
use Carbon\Carbon;
use Database\Factories\MonthlySummaryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A frozen report of one closed month.
 *
 * Everything the reader ever sees comes out of `payload`: the email, the card,
 * the history screen and the public page. Nothing recomputes, so a figure a user
 * shared on a Tuesday still reads the same on Friday, and the AI analysis is
 * paid for once no matter how many times the send is retried.
 *
 * @property string $period YYYY-MM of the closed month
 * @property array<string, mixed> $payload
 * @property ?string $ai_analysis
 * @property ?Carbon $ai_generated_at
 * @property MonthlySummaryCard $card
 * @property bool $complete
 * @property ?string $share_token
 * @property ?Carbon $shared_at
 * @property ?Carbon $sent_at
 * @property ?Carbon $dismissed_at
 */
class MonthlySummary extends Model
{
    /** @use HasFactory<MonthlySummaryFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'space_id',
        'period',
        'payload',
        'ai_analysis',
        'ai_generated_at',
        'card',
        'complete',
        'share_token',
        'shared_at',
        'sent_at',
    ];

    protected $hidden = [
        'space_id',
        'share_token',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'card' => MonthlySummaryCard::class,
            'complete' => 'boolean',
            'ai_generated_at' => 'datetime',
            'shared_at' => 'datetime',
            'sent_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * First day of the month this summary reports on.
     */
    public function periodStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->period.'-01')->startOfMonth();
    }

    /**
     * Read a dotted path out of the frozen payload.
     */
    public function figure(string $path, mixed $default = null): mixed
    {
        return data_get($this->payload, $path, $default);
    }

    /**
     * Mint the public link on first use. Deliberately lazy: a user who never
     * asks to share has no public URL at all.
     */
    public function mintShareToken(): string
    {
        if ($this->share_token === null) {
            $this->forceFill([
                'share_token' => Str::random(48),
                'shared_at' => now(),
            ])->save();
        }

        return $this->share_token;
    }

    /**
     * Withdraw the public link. The page 404s from that moment on.
     */
    public function revokeShareToken(): void
    {
        $this->forceFill(['share_token' => null, 'shared_at' => null])->save();
    }

    /**
     * Put the dashboard notice away. Kept on the summary rather than in the
     * browser, so it stays away on every device and across logins.
     */
    public function dismiss(): void
    {
        $this->forceFill(['dismissed_at' => now()])->save();
    }
}
