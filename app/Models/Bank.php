<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Bank extends Model
{
    /** @use HasFactory<\Database\Factories\BankFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'name_iv',
        'logo',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
