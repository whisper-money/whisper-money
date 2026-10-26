<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ConvertedAmountsRequest;
use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;

/**
 * Many amounts converted into the user's currency, each at its own date, for
 * the day totals of the transactions table.
 *
 * The table only asks for the rows held in another currency, all of them in one
 * request, so a page of foreign rows never turns into a request per row.
 */
class ConvertedAmountsController extends Controller
{
    public function __construct(private ExchangeRateService $exchangeRateService) {}

    public function __invoke(ConvertedAmountsRequest $request): JsonResponse
    {
        $target = $request->user()->currency_code;
        $items = $request->items();

        $this->exchangeRateService->preloadRates($target, array_column($items, 'date'));

        return response()->json([
            // In the order asked, null where the day holds no rate: the table
            // leaves that day's total out rather than print a wrong figure.
            'amounts' => array_map(
                fn (array $item): ?int => $this->exchangeRateService->convertOrNull(
                    $item['from'],
                    $target,
                    $item['amount'],
                    $item['date'],
                ),
                $items,
            ),
        ]);
    }
}
