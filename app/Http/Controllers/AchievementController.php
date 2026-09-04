<?php

namespace App\Http\Controllers;

use App\Features\Achievements;
use App\Services\Achievements\Progress;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

/**
 * The progress screen: every medal a reader has earned, and every one still to
 * come.
 *
 * Reached from the account menu rather than the main navigation, because it is
 * something to look back at rather than somewhere to work. The monthly
 * summaries sit beside it in that menu on their own screen: both are records of
 * what already happened, but a report is read once and a medal is collected,
 * and stacking them made one page answer two questions.
 */
class AchievementController extends Controller
{
    public function __construct(private Progress $progress) {}

    public function index(Request $request): Response
    {
        abort_unless(Feature::active(Achievements::class), 404);

        $user = $request->user();

        return Inertia::render('achievements/index', $this->progress->for($user));
    }
}
