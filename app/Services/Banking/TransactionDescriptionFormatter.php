<?php

namespace App\Services\Banking;

use App\Services\Banking\Formatters\BankFormatter;
use App\Services\Banking\Formatters\BbvaFormatter;
use App\Services\Banking\Formatters\RemittanceTagFormatter;

class TransactionDescriptionFormatter
{
    /** @var BankFormatter[] */
    private array $formatters;

    public function __construct()
    {
        // Ordered most specific first: a formatter keyed on the description
        // itself knows the shape it gets, a bank-keyed one only guesses.
        $this->formatters = [
            new RemittanceTagFormatter,
            new BbvaFormatter,
        ];
    }

    /**
     * @return array{description: string, original_description: string|null}
     */
    public function format(string $description, ?string $bankName): array
    {
        foreach ($this->formatters as $formatter) {
            if (! $formatter->matches($description, $bankName)) {
                continue;
            }

            $formatted = $formatter->format($description);

            return [
                'description' => $formatted,
                'original_description' => $formatted !== $description ? $description : null,
            ];
        }

        return ['description' => $description, 'original_description' => null];
    }
}
