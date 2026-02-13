<?php

namespace App\Services\Banking\Formatters;

interface BankFormatter
{
    public function matches(string $bankName): bool;

    public function format(string $description): string;
}
