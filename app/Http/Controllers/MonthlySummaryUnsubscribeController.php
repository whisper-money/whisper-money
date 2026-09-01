<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

/**
 * One-click unsubscribe from the monthly summary.
 *
 * Reached from a signed link in the footer and from the `List-Unsubscribe`
 * header, which is why it answers POST as well as GET: RFC 8058 clients (Gmail,
 * Apple Mail) post to it without a browser, and a mailbox provider that can
 * unsubscribe a reader in one click is a mailbox provider that delivers the next
 * send to the inbox.
 *
 * No login: a reader who has just decided they do not want an email must not be
 * asked to authenticate first. The signature is the authorisation, and the only
 * thing the link can do is switch one preference off.
 */
class MonthlySummaryUnsubscribeController extends Controller
{
    public function __invoke(Request $request, User $user): Response
    {
        $user->setting()->updateOrCreate(
            ['user_id' => $user->id],
            ['notify_monthly_summary' => false],
        );

        if ($request->isMethod('post')) {
            // One-click clients want an empty 200, not a page.
            return response('', 200);
        }

        return response(View::make('monthly-summaries.unsubscribed')->render());
    }
}
