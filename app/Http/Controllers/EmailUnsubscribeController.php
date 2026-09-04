<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

/**
 * One-click unsubscribe from a recurring email.
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
 *
 * Which preference comes from the route rather than the query string, so a
 * signed link can never be edited into turning something else off.
 */
class EmailUnsubscribeController extends Controller
{
    /**
     * The preferences a link may switch off, and what to say once it has.
     *
     * @return array<string, array{column: string, title: string, body: string}>
     */
    private function preferences(): array
    {
        return [
            'monthly_summary' => [
                'column' => 'notify_monthly_summary',
                'title' => __('Monthly summary turned off'),
                'body' => __('You will not get the monthly report or its reminder again. Everything else stays as it was.'),
            ],
            'achievements' => [
                'column' => 'notify_achievements',
                'title' => __('Achievement emails turned off'),
                'body' => __('You will not get an email when you unlock an achievement again. They still show up in the app. Everything else stays as it was.'),
            ],
        ];
    }

    public function __invoke(Request $request, User $user, string $preference): Response
    {
        $chosen = $this->preferences()[$preference] ?? abort(404);

        $user->setting()->updateOrCreate(
            ['user_id' => $user->id],
            [$chosen['column'] => false],
        );

        if ($request->isMethod('post')) {
            // One-click clients want an empty 200, not a page.
            return response('', 200);
        }

        return response(View::make('emails.unsubscribed', [
            'title' => $chosen['title'],
            'body' => $chosen['body'],
        ])->render());
    }
}
