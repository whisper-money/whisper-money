<?php

namespace App\Services\Banking\Formatters;

/**
 * Strips the ISO 20022 `/TXT/` remittance tag some banks (Bankinter, Unicaja)
 * leave in the unstructured remittance information, so the description is the
 * merchant or counterparty instead of `/TXT/D|MERCHANT`. The tag identifies
 * itself, so this formatter is keyed on the description rather than on a bank
 * name and works for any bank that ships the same shape.
 */
class RemittanceTagFormatter implements BankFormatter
{
    public const TAG = '/TXT/';

    /**
     * Debit/credit marker the bank prepends to the text (`D` for debe, `H` for
     * haber). It only repeats the sign of the amount.
     */
    private const CREDIT_DEBIT_MARKER = '/^[DH]\|/';

    /**
     * Card payments carry the settlement date after a pipe, usually preceded by
     * the purchase date glued to a truncated merchant name:
     * `CONDIS SANT JUST DESV07/07/26|20260714`, `RECIBO MES TARJETA|20260806`.
     */
    private const CARD_DATES_SUFFIX = '/\s*(\d{2}\/\d{2}\/\d{2})?\|\d{8}$/';

    public function matches(string $description, ?string $bankName): bool
    {
        return str_starts_with($description, self::TAG);
    }

    public function format(string $description): string
    {
        $text = mb_substr($description, mb_strlen(self::TAG));
        $text = (string) preg_replace(self::CREDIT_DEBIT_MARKER, '', $text);
        $text = (string) preg_replace(self::CARD_DATES_SUFFIX, '', $text);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        // Internal marker on card charges and refunds ("#RECOBRO RECIBO VISA").
        $text = trim(ltrim(trim($text), '#'));

        return $text === '' ? $description : $text;
    }
}
