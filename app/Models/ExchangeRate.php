<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\ExchangeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $base_currency
 * @property Carbon $date
 * @property array<string, float> $rates
 */
class ExchangeRate extends Model
{
    /** @use HasFactory<ExchangeRateFactory> */
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
