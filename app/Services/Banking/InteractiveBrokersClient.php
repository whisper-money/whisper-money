<?php

namespace App\Services\Banking;

use App\Exceptions\Banking\TransientBankingProviderException;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use SimpleXMLElement;

/**
 * Interactive Brokers Flex Web Service client.
 *
 * Flex is a two-step pull: SendRequest returns a reference code, GetStatement
 * returns the XML once IB finishes generating it. IB answers with HTTP 200 even
 * for failures (the error lives in the XML), so we translate its status/error
 * codes into the exceptions SyncBankingConnectionJob already understands:
 * RequestException(401) for token problems, RequestException(429) for throttling,
 * TransientBankingProviderException for anything retryable.
 */
class InteractiveBrokersClient
{
    private const BASE_URL = 'https://ndcdyn.interactivebrokers.com/AccountManagement/FlexWebService';

    private const VERSION = '3';

    private const MAX_STATEMENT_ATTEMPTS = 5;

    private const STATEMENT_RETRY_SECONDS = 3;

    private const HTTP_CONNECT_TIMEOUT_SECONDS = 5;

    private const HTTP_TIMEOUT_SECONDS = 15;

    public function __construct(
        private string $token,
        private string $queryId,
    ) {}

    /**
     * Fetch the Flex statement and return one entry per IB account.
     *
     * @return array<string, array{account_id: string, currency: string, navByDate: array<string, float>, investedAmount: float|null}>
     */
    public function fetchStatement(): array
    {
        $referenceCode = $this->sendRequest();
        $xml = $this->getStatement($referenceCode);

        return $this->parseStatement($xml);
    }

    private function sendRequest(): string
    {
        $response = $this->http()->get(self::BASE_URL.'/SendRequest', [
            't' => $this->token,
            'q' => $this->queryId,
            'v' => self::VERSION,
        ]);

        $response->throw();

        $xml = $this->loadXml($response->body());

        if ((string) $xml->Status !== 'Success') {
            $this->throwForError((string) $xml->ErrorCode, (string) $xml->ErrorMessage);
        }

        $referenceCode = (string) $xml->ReferenceCode;

        if ($referenceCode === '') {
            throw new TransientBankingProviderException(
                'Interactive Brokers did not return a reference code',
                provider: 'interactivebrokers',
            );
        }

        return $referenceCode;
    }

    private function getStatement(string $referenceCode): SimpleXMLElement
    {
        for ($attempt = 1; $attempt <= self::MAX_STATEMENT_ATTEMPTS; $attempt++) {
            $response = $this->http()->get(self::BASE_URL.'/GetStatement', [
                't' => $this->token,
                'q' => $referenceCode,
                'v' => self::VERSION,
            ]);

            $response->throw();

            $xml = $this->loadXml($response->body());

            if ($xml->getName() === 'FlexQueryResponse') {
                return $xml;
            }

            $code = (string) $xml->ErrorCode;
            $message = (string) $xml->ErrorMessage;

            if (! $this->isStillGenerating($code, $message)) {
                $this->throwForError($code, $message);
            }

            if ($attempt < self::MAX_STATEMENT_ATTEMPTS) {
                Sleep::for(self::STATEMENT_RETRY_SECONDS)->seconds();
            }
        }

        throw new TransientBankingProviderException(
            'Interactive Brokers statement was not ready in time',
            provider: 'interactivebrokers',
            providerCode: '1019',
        );
    }

    /**
     * @return array<string, array{account_id: string, currency: string, navByDate: array<string, float>, investedAmount: float|null}>
     */
    private function parseStatement(SimpleXMLElement $xml): array
    {
        $accounts = [];

        if (! isset($xml->FlexStatements->FlexStatement)) {
            return $accounts;
        }

        foreach ($xml->FlexStatements->FlexStatement as $statement) {
            $accountId = (string) $statement['accountId'];

            if ($accountId === '') {
                continue;
            }

            $navByDate = [];
            $cashByDate = [];

            if (isset($statement->EquitySummaryInBase->EquitySummaryByReportDateInBase)) {
                foreach ($statement->EquitySummaryInBase->EquitySummaryByReportDateInBase as $row) {
                    $date = $this->normalizeDate((string) $row['reportDate']);
                    $total = (string) $row['total'];

                    if ($date === null || $total === '') {
                        continue;
                    }

                    $navByDate[$date] = (float) $total;
                    $cashByDate[$date] = (float) $row['cash'];
                }
            }

            ksort($navByDate);

            // Cost basis only exists on the current snapshot (OpenPositions), so
            // invested_amount — and therefore profit — is only known for the
            // latest date; historical NAV rows store balance alone.
            $costBasisBase = 0.0;
            $hasPositions = isset($statement->OpenPositions->OpenPosition);

            if ($hasPositions) {
                foreach ($statement->OpenPositions->OpenPosition as $position) {
                    $fxRate = (float) ($position['fxRateToBase'] ?: 1);
                    $costBasisBase += (float) $position['costBasisMoney'] * $fxRate;
                }
            }

            $investedAmount = null;

            if (! empty($navByDate) && $hasPositions) {
                $latestDate = array_key_last($navByDate);
                $investedAmount = $costBasisBase + ($cashByDate[$latestDate] ?? 0.0);
            }

            $accounts[$accountId] = [
                'account_id' => $accountId,
                'currency' => (string) ($statement->AccountInformation['currency'] ?? ''),
                'navByDate' => $navByDate,
                'investedAmount' => $investedAmount,
            ];
        }

        return $accounts;
    }

    private function http(): PendingRequest
    {
        return Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::HTTP_TIMEOUT_SECONDS);
    }

    private function loadXml(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new TransientBankingProviderException(
                'Interactive Brokers returned an unreadable response',
                provider: 'interactivebrokers',
            );
        }

        return $xml;
    }

    private function isStillGenerating(string $code, string $message): bool
    {
        $text = strtolower($message);

        if (str_contains($text, 'too many')) {
            return false;
        }

        return in_array($code, ['1005', '1006', '1019'], true)
            || str_contains($text, 'in progress')
            || str_contains($text, 'try again shortly');
    }

    /**
     * Map an IB Flex failure onto the right exception. IB's numeric codes are
     * inconsistent across endpoints, so we classify on the message text too.
     *
     * ponytail: text-match because IB returns 200 + XML; tighten to exact codes
     * only if a real account shows the message wording drifting.
     */
    private function throwForError(string $code, string $message): never
    {
        Log::warning('Interactive Brokers Flex error', ['code' => $code, 'message' => $message]);

        $text = strtolower($code.' '.$message);

        // Rate-limit first: IB's throttle message itself says "from this token",
        // which would otherwise trip the token (auth) branch below.
        if (str_contains($text, 'too many') || $code === '1018') {
            throw new RequestException(
                new Response(new PsrResponse(429, [], $message ?: 'Too many Flex requests')),
            );
        }

        // Bad token or bad/deleted query ID: surface as an auth failure so the
        // user is prompted to fix the credentials instead of retrying forever.
        if (str_contains($text, 'token') || str_contains($text, 'invalid') || $code === '1020') {
            throw new RequestException(
                new Response(new PsrResponse(401, [], $message ?: 'Invalid Flex token or query ID')),
            );
        }

        throw new TransientBankingProviderException(
            $message !== '' ? $message : "Interactive Brokers error {$code}",
            provider: 'interactivebrokers',
            providerCode: $code !== '' ? $code : null,
        );
    }

    private function normalizeDate(string $date): ?string
    {
        $date = trim($date);

        if (preg_match('/^\d{8}$/', $date) === 1) {
            return substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            return $date;
        }

        return null;
    }
}
