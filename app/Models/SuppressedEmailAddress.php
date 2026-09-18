<?php

namespace App\Models;

use App\Enums\SuppressionReason;
use App\Http\Controllers\SesFeedbackController;
use Carbon\Carbon;
use Database\Factories\SuppressedEmailAddressFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An address SES told us to stop mailing, through the bounce and complaint
 * feedback SNS publishes to {@see SesFeedbackController}.
 *
 * Keyed by the address rather than by the user on purpose: bounces also arrive
 * for addresses that were never users (report recipients, deleted accounts), and
 * suppression that follows the address means a reader who fixes a typo in their
 * email starts receiving again without anyone cleaning up a row by hand.
 *
 * @property string $email
 * @property SuppressionReason $reason
 * @property Carbon $suppressed_at
 */
class SuppressedEmailAddress extends Model
{
    /** @use HasFactory<SuppressedEmailAddressFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'email',
        'reason',
        'suppressed_at',
    ];

    protected function casts(): array
    {
        return [
            'reason' => SuppressionReason::class,
            'suppressed_at' => 'datetime',
        ];
    }

    /**
     * Record the latest feedback for an address. A second bounce, or a complaint
     * after a bounce, overwrites the reason and the date: what the mailbox is
     * saying now is what matters.
     */
    public static function suppress(string $email, SuppressionReason $reason): void
    {
        $address = self::normalize($email);

        if ($address === '') {
            return;
        }

        self::updateOrCreate(
            ['email' => $address],
            ['reason' => $reason, 'suppressed_at' => now()],
        );
    }

    public static function isSuppressed(string $email): bool
    {
        return self::query()->where('email', self::normalize($email))->exists();
    }

    /**
     * Addresses are stored lowercased so a bounce for `Foo@Example.com` also
     * silences the `foo@example.com` we hold on the user.
     */
    private static function normalize(string $email): string
    {
        return Str::lower(trim($email));
    }
}
