<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\CursorPaginator;
use App\Http\ApiV1\Documentation\Paginated;
use App\Http\ApiV1\Documentation\ScopedToSpace;
use App\Http\Requests\Api\V1\StoreAccountBalanceRequest;
use App\Http\Resources\V1\AccountBalanceResource;
use App\Services\Accounts\AccountBalanceRecorder;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Balances', 'A balance is a snapshot of what an account held at the end of one day, in the account currency\'s minor units. A bank-connected account gets its balances from the bank sync; you record those of a manual account yourself. A credit card has no balance: it has a credit limit, and what is in use of it comes from its transactions.', weight: 30)]
class AccountBalanceController extends Controller
{
    /**
     * List an account's balances
     *
     * The account's balance snapshots, the most recent day first.
     *
     * @param  string  $account  The account's id.
     */
    #[ScopedToSpace]
    #[Paginated(AccountBalanceResource::class)]
    public function index(Request $request, string $account): AnonymousResourceCollection
    {
        return CursorPaginator::paginate(
            $this->account($request, $account)->balances()->getQuery(),
            $request,
            [['balance_date', 'desc'], ['id', 'desc']],
            AccountBalanceResource::class,
        );
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
    #[ScopedToSpace]
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
