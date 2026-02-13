<?php

namespace App\Services\Banking;

use App\Services\Banking\Formatters\BankFormatter;
use App\Services\Banking\Formatters\BbvaFormatter;

class TransactionDescriptionFormatter
{
    /** @var BankFormatter[] */
    private array $formatters;

    public function __construct()
    {
        $this->formatters = [
            new BbvaFormatter,
        ];
    }

    /**
     * @return array{description: string, original_description: string|null}
     */
    public function format(string $description, ?string $bankName): array
    {
        if ($bankName) {
            foreach ($this->formatters as $formatter) {
                if ($formatter->matches($bankName)) {
                    $formatted = $formatter->format($description);

                    return [
                        'description' => $formatted,
                        'original_description' => $formatted !== $description ? $description : null,
                    ];
                }
            }
        }

        return ['description' => $description, 'original_description' => null];
    }
}
