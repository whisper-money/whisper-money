<?php

namespace App\Http\Controllers;

use App\Services\Notifications\NotificationFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The bell's full page, and what happens when a row is read.
 *
 * Rows are marked read by being opened rather than by being seen: the panel
 * lists them and a click sends the reader through {@see show()}, which records
 * the read and lands on whatever the row announced.
 */
class NotificationController extends Controller
{
    public function __construct(private NotificationFeed $feed) {}

    public function index(Request $request): Response
    {
        return Inertia::render('notifications/index', [
            'notifications' => $this->feed->page($request->user()),
        ]);
    }

    /**
     * A GET, so a row in the panel can be a plain link: it marks the row read
     * and redirects to what the row is about.
     */
    public function show(Request $request, string $notification): RedirectResponse
    {
        // Found through the reader's own relation rather than bound and then
        // checked, so another reader's row is never even loaded.
        $row = $request->user()->notifications()->findOrFail($notification);

        return redirect($this->feed->open($row) ?? route('notifications.list'));
    }

    /**
     * No redirect: the panel hides the dots on its own and fires this in the
     * background, because a full visit would reload every deferred prop on the
     * page just to clear a badge.
     */
    public function readAll(Request $request): HttpResponse
    {
        $this->feed->markAllRead($request->user());

        return response()->noContent();
    }
}
