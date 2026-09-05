<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ExchangeRateRequest;
use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;

/**
 * One amount converted between two currencies, for the transaction modal.
 *
 * Everything else converts server-side while it adds transactions up; the modal
 * is the one place that needs a single figure on demand, so it asks here rather
 * than shipping a rate table to the browser.
 */
class ExchangeRateController extends Controller
{
    public function __construct(private ExchangeRateService $exchangeRateService) {}

    public function __invoke(ExchangeRateRequest $request): JsonResponse
    {
        return response()->json([
            // Null when the day holds no rate: the modal shows the original
            // alone rather than printing a figure it could not convert.
            'amount' => $this->exchangeRateService->convertOrNull(
                $request->string('from')->toString(),
                $request->string('to')->toString(),
                $request->integer('amount'),
                $request->string('date')->toString(),
            ),
        ]);
    }
}
