<?php

namespace App\Services\Banking;

class TransactionCounterpartyExtractor
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{creditor_name: string|null, debtor_name: string|null}
     */
    public static function fromPayload(array $data): array
    {
        return [
            'creditor_name' => self::name($data['creditor']['name'] ?? null),
            'debtor_name' => self::name($data['debtor']['name'] ?? null),
        ];
    }

    private static function name(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $name = trim($value);

        if ($name === '') {
            return null;
        }

        return mb_substr($name, 0, 255);
    }
}
