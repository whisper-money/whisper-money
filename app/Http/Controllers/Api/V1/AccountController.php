<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiV1\CursorPaginator;
use App\Http\ApiV1\Documentation\Paginated;
use App\Http\ApiV1\Documentation\ScopedToSpace;
use App\Http\Requests\Api\V1\StoreAccountRequest;
use App\Http\Requests\Api\V1\UpdateAccountRequest;
use App\Http\Resources\V1\AccountResource;
use App\Models\Account;
use App\Services\Accounts\AccountWriteRules;
use App\Services\AccountWriteService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Accounts', 'An account is a bookkeeping record of money held somewhere: a manual one you keep up to date yourself, or a bank-connected one the bank sync keeps up to date. Nothing here moves money; that happens at the bank.', weight: 20)]
class AccountController extends Controller
{
    public function __construct(private AccountWriteService $accountWriteService) {}

    /**
     * List accounts
     *
     * The accounts in a space, newest first, archived ones included. Every
     * member of a space sees all of its accounts, whoever owns them.
     */
    #[ScopedToSpace]
    #[Paginated(AccountResource::class)]
    public function index(Request $request): AnonymousResourceCollection
    {
        return CursorPaginator::paginate(
            Account::query()->forSpace($this->space($request))->with(['bank:id,name', 'creditCardDetail']),
            $request,
            [['created_at', 'desc'], ['id', 'desc']],
            AccountResource::class,
        );
    }

    /**
     * Retrieve an account
     *
     * @param  string  $account  The account's id.
     */
    #[ScopedToSpace]
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
    #[ScopedToSpace]
    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->accountWriteService->create(
            $this->user($request),
            $request->validated(),
            $this->space($request)->id,
        );

        return (new AccountResource($account))->response()->setStatusCode(201);
    }

    /**
     * Update an account
     *
     * Changes only the fields you send. On a manual account that is all of
     * them; on a bank-connected one only the name, the type (among
     * `checking`, `credit_card`, `savings` and `others`) and the ownership
     * fields, since its currency and bank come from the bank. Only the
     * account's owner can edit it, not the other members of its space.
     *
     * Changing a loan or a property to another type keeps its details and any
     * mortgage link on file but stops using them. Archiving, hiding and
     * deleting an account happen in the app.
     *
     * @param  string  $account  The account's id.
     */
    #[ScopedToSpace]
    public function update(UpdateAccountRequest $request, string $account): AccountResource
    {
        $model = $request->account();

        // Any other field sent along is saved even when the loan details
        // cannot be created yet.
        AccountWriteRules::throwIfAny(AccountWriteRules::missingLoanFieldErrors(
            $this->accountWriteService->update($model, $request->validated()),
        ));

        return new AccountResource($model->refresh());
    }
}
