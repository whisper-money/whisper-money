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

        $name = preg_replace('/[;\s]+/u', ' ', trim($value));

        if (! is_string($name)) {
            return null;
        }

        $name = trim($name);

        if ($name === '' || preg_match('/[\pL\pN]/u', $name) !== 1) {
            return null;
        }

        return mb_substr($name, 0, 255);
    }
}
