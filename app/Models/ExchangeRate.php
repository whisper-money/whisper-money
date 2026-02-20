<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    /** @use HasFactory<\Database\Factories\ExchangeRateFactory> */
    use HasFactory;

    protected $fillable = [
        'base_currency',
        'date',
        'rates',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'rates' => 'array',
        ];
    }
}
