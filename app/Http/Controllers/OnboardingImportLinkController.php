<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendOnboardingImportLinkRequest;
use App\Mail\OnboardingImportLinkEmail;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * "Send me a link to import from my computer": the way out of the import step
 * for someone on a phone, where getting a bank export is the hard part.
 */
class OnboardingImportLinkController extends Controller
{
    /** The flash key the landing leaves for the page it redirects to. */
    public const FLASH_KEY = 'import_link_account_id';

    /**
     * Email the user a link back to the import for one of their accounts.
     */
    public function store(SendOnboardingImportLinkRequest $request): JsonResponse
    {
        $user = $request->user();
        $account = $user->accounts()->findOrFail($request->validated('account_id'));

        abort_unless($account->acceptsManualMovements(), 422, __('This account cannot take movements.'));

        Mail::to($user)->queue(new OnboardingImportLinkEmail($user, $account));

        return response()->json(status: 202);
    }

    /**
     * Where the emailed link lands: back on the import for that account. The
     * onboarding's import step while the onboarding is not finished, the
     * app's importer once it is. The account travels in the session, not in
     * the URL of the page the user ends up on.
     */
    public function show(Request $request, Account $account): RedirectResponse
    {
        $user = $request->user();

        // A link forwarded to, or opened by, somebody else's session.
        abort_unless($account->user_id === $user->id, 404);

        // Archived or connected to a bank since the link was sent: the import
        // still opens, it just asks which account instead of naming this one.
        if ($account->acceptsManualMovements()) {
            $request->session()->flash(self::FLASH_KEY, $account->id);
        }

        if ($user->isOnboarded()) {
            return redirect()->route('transactions.index');
        }

        return redirect()->route('onboarding', ['step' => 'import-transactions']);
    }
}
