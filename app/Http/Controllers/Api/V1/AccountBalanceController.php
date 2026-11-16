<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\Listings;
use App\Http\Requests\Api\V1\StoreAccountBalanceRequest;
use App\Http\Resources\V1\AccountBalanceResource;
use App\Services\Accounts\AccountBalanceRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountBalanceController extends Controller
{
    /**
     * List an account's balances
     *
     * The account's balance snapshots, the most recent day first.
     *
     * @param  string  $account  The account's id.
     */
    public function index(Request $request, Listings $listings, string $account): AnonymousResourceCollection
    {
        return $listings->balances($this->account($request, $account))->paginate($request);
    }

    /**
     * Record a balance
     *
     * Records what a manual account held on a day, replacing the snapshot
     * already recorded for that day. Answers `201` when it adds a snapshot and
     * `200` when it replaces one. A bank-connected account is refused, since
     * the next sync would overwrite the figure, and so is a credit card, which
     * has no balance; both answer `422` with the error under `account`.
     *
     * @param  string  $account  The account's id.
     */
    public function store(StoreAccountBalanceRequest $request, AccountBalanceRecorder $recorder, string $account): JsonResponse
    {
        $validated = $request->validated();

        $balance = $recorder->record(
            $request->account(),
            $validated['balance'],
            $validated['balance_date'] ?? null,
            $validated['invested_amount'] ?? null,
        );

        $response = (new AccountBalanceResource($balance))->response();

        if ($balance->wasRecentlyCreated) {
            return $response->setStatusCode(201);
        }

        return $response;
    }
}
