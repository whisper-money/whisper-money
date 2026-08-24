<?php

namespace App\Models;

use Database\Factories\SpaceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $owner_id
 * @property string $name
 * @property bool $personal
 */
class Space extends Model
{
    /** @use HasFactory<SpaceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'owner_id',
        'name',
        'personal',
    ];

    protected function casts(): array
    {
        return [
            'personal' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Members invited into the space (excludes the owner, who is implicit).
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'space_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<SpaceInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(SpaceInvitation::class);
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @return HasMany<BankingConnection, $this> */
    public function bankingConnections(): HasMany
    {
        return $this->hasMany(BankingConnection::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<Label, $this> */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class);
    }

    /** @return HasMany<Budget, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /** @return HasMany<AutomationRule, $this> */
    public function automationRules(): HasMany
    {
        return $this->hasMany(AutomationRule::class);
    }

    /** @return HasMany<SavedFilter, $this> */
    public function savedFilters(): HasMany
    {
        return $this->hasMany(SavedFilter::class);
    }

    /**
     * Whether the given user owns or is a member of this space. The owner check
     * short-circuits without a query for the common (personal-space) case.
     */
    public function hasMember(User $user): bool
    {
        if ($this->owner_id === $user->id) {
            return true;
        }

        return $this->members()->whereKey($user->id)->exists();
    }

    /**
     * The role the user holds in this space: owner, the pivot role, or null when
     * they have no access.
     */
    public function roleFor(User $user): ?string
    {
        if ($this->owner_id === $user->id) {
            return 'owner';
        }

        return $this->members()->whereKey($user->id)->value('role');
    }
}
