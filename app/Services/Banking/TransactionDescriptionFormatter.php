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
        // First match wins, so a formatter keyed on the description — which
        // knows the exact shape it gets — is tried before a bank-keyed one,
        // which only guesses from the connection.
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
