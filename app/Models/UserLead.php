<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserLead extends Model
{
    /** @use HasFactory<\Database\Factories\UserLeadFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'email',
    ];
}
