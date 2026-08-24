<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\UserLeadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Retained accessor for the pre-launch waitlist leads. The waitlist capture and
 * invitation flow has been removed, but the user_leads table is kept so those
 * contacts (and any promo codes allocated to them) are not lost. New signups
 * whose email matches a lead still receive the lead's promo code at checkout.
 *
 * @property string $email
 * @property ?string $promo_code_monthly
 * @property ?string $promo_code_yearly
 * @property ?CarbonInterface $email_verified_at
 */
class UserLead extends Model
{
    /** @use HasFactory<UserLeadFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'email',
        'email_verified_at',
        'position',
        'referral_code',
        'locale',
        'promo_code_monthly',
        'promo_code_yearly',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }
}
