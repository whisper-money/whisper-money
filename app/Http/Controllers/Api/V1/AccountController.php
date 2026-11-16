<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\Listings;
use App\Http\Requests\Api\V1\StoreAccountRequest;
use App\Http\Requests\Api\V1\UpdateAccountRequest;
use App\Http\Resources\V1\AccountResource;
use App\Services\Accounts\AccountWriteRules;
use App\Services\AccountWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AccountController extends Controller
{
    public function __construct(private AccountWriteService $accountWriteService) {}

    /**
     * List accounts
     *
     * Your accounts in a space, newest first, archived ones included. As in
     * the app, another member's accounts are not listed.
     */
    public function index(Request $request, Listings $listings): AnonymousResourceCollection
    {
        return $listings->accounts($this->user($request), $this->space($request))->paginate($request);
    }

    /**
     * Retrieve an account
     *
     * @param  string  $account  The account's id.
     */
    public function show(Request $request, string $account): AccountResource
    {
        return new AccountResource($this->account($request, $account));
    }

    /**
     * Create an account
     *
     * Opens a manual account of any type, optionally with today's balance. A
     * loan or a property takes its own details, and given a `balance` too it
     * gets the monthly balance history in between generated, the older part
     * in the background. A bank-connected account cannot be created here: it
     * is only born when you connect your bank in the Whisper Money app, so
     * `banking_connection_id`, `external_account_id` and `is_connected` are
     * refused.
     */
    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->accountWriteService->create(
            $this->user($request),
            $request->accountAttributes(),
            $this->space($request)->id,
        );

        // Refreshed so the columns the database defaults (the ownership share)
        // come back filled in.
        return (new AccountResource($account->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Update an account
     *
     * Changes only the fields you send. On a manual account that is all of
     * them; on a bank-connected one only the name, the type (among
     * `checking`, `credit_card`, `savings` and `others`) and the ownership
     * fields, since its currency and bank come from the bank. A loan
     * with no details yet needs `annual_interest_rate`, `loan_term_months` and
     * `original_amount` together; sending only some of them is a `422` and
     * nothing in the request is saved.
     *
     * Changing a loan or a property to another type keeps its details and any
     * mortgage link on file but stops using them. Archiving, hiding and
     * deleting an account happen in the app.
     *
     * @param  string  $account  The account's id.
     */
    public function update(UpdateAccountRequest $request, string $account): AccountResource
    {
        $model = $request->account();

        // A loan whose details cannot be created yet is refused whole: the
        // transaction rolls back whatever else the request changed.
        DB::transaction(fn () => AccountWriteRules::throwIfAny(AccountWriteRules::missingLoanFieldErrors(
            $this->accountWriteService->update($model, $request->accountAttributes()),
            restSaved: false,
        )));

        return new AccountResource($model->refresh());
    }
}
