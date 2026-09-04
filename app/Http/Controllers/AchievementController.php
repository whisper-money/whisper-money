<?php

namespace App\Http\Controllers;

use App\Enums\CardFormat;
use App\Enums\CardTheme;
use App\Features\Achievements;
use App\Models\Achievement;
use App\Services\Achievements\CardRenderer;
use App\Services\Achievements\Catalog;
use App\Services\Achievements\Ladders;
use App\Services\Achievements\Progress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

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
    public function __construct(
        private Progress $progress,
        private Catalog $catalog,
        private Ladders $ladders,
        private CardRenderer $renderer,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless(Feature::active(Achievements::class), 404);

        $user = $request->user();

        return Inertia::render('achievements/index', $this->progress->for($user));
    }

    /**
     * Serve one medal as a PNG. Rendered on first request and cached on the
     * public disk.
     *
     * Only a medal this reader has actually earned: a locked one has no name on
     * the screen for a reason, and handing its picture out through this route
     * would read it out anyway.
     *
     * `?amount=0` leaves a money medal's figure off the card. The default is on
     * — the figure is the milestone — so withholding it is a deliberate act in
     * the share dialog.
     */
    public function card(Request $request, string $medal, string $format, string $theme): StreamedResponse
    {
        abort_unless(Feature::active(Achievements::class), 404);

        $definition = $this->catalog->find($medal);
        $format = CardFormat::tryFrom($format);
        $theme = CardTheme::tryFrom($theme);

        abort_if($definition === null || $theme === null, 404);
        abort_unless($format !== null && in_array($format, CardFormat::forAchievements(), strict: true), 404);

        $achievement = Achievement::query()
            ->where('user_id', $request->user()->id)
            ->where('key', $medal)
            ->first();

        abort_if($achievement === null, 404);

        $amount = ! $request->has('amount') || $request->boolean('amount');

        try {
            $path = $this->renderer->path(
                $achievement,
                $definition,
                $this->ladders->currencyFor($request->user()->currency_code),
                $format,
                $theme,
                $amount,
            );
        } catch (Throwable $exception) {
            report($exception);
            abort(503);
        }

        $disk = Storage::disk(CardRenderer::DISK);

        if ($request->boolean('preview')) {
            // Minutes, not days. Long enough that picking a shape, changing
            // your mind and coming back does not redraw anything, and short
            // enough that the browser is not holding a picture of somebody's
            // money for the rest of the day.
            return $disk->response($path, headers: ['Cache-Control' => 'private, max-age=300']);
        }

        return $disk->download($path, $this->filename($medal, $format, $theme));
    }

    private function filename(string $medal, CardFormat $format, CardTheme $theme): string
    {
        return 'whisper-money-'.str_replace('.', '-', $medal)."-{$format->value}-{$theme->value}.png";
    }
}
