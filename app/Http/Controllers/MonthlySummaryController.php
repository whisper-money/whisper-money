<?php

namespace App\Http\Controllers;

use App\Enums\CardFormat;
use App\Enums\CardTheme;
use App\Enums\MonthlySummaryCard;
use App\Models\MonthlySummary;
use App\Services\MonthlySummary\AnalysisWriter;
use App\Services\MonthlySummary\CardPicker;
use App\Services\MonthlySummary\CardRenderer;
use App\Services\MonthlySummary\EmailPresenter;
use App\Services\Notifications\NotificationFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The monthly summaries a user can look back at, and the cards they can share.
 *
 * The screen exists because the email is not the only way in: someone who never
 * opens email still gets a notice on their dashboard, and a summary that has
 * been sent should not stop existing when the message is archived.
 */
class MonthlySummaryController extends Controller
{
    public function __construct(
        private CardPicker $picker,
        private CardRenderer $renderer,
        private EmailPresenter $presenter,
        private AnalysisWriter $analysis,
        private NotificationFeed $notifications,
    ) {}

    public function index(Request $request): Response
    {
        $summaries = $request->user()->monthlySummaries()
            ->whereNotNull('sent_at')
            ->orderByDesc('period')
            ->get();

        return Inertia::render('monthly-summaries/index', [
            'summaries' => $summaries->map(fn (MonthlySummary $summary): array => $this->listRow($summary))->all(),
        ]);
    }

    public function show(Request $request, MonthlySummary $summary): Response
    {
        abort_unless($summary->user_id === $request->user()->id, 404);

        // Reading the report is reading its row in the bell, whichever way in.
        $this->notifications->markReadForSummary($summary);

        $pro = $this->analysis->eligible($request->user());

        return Inertia::render('monthly-summaries/show', [
            'summary' => $this->listRow($summary),
            'report' => $this->presenter->present($summary, app()->getLocale(), $pro),
            'analysis' => $summary->ai_analysis,
            'cards' => $this->cardOptions($summary),
            'shareUrl' => $summary->share_token === null ? null : route('monthly-summaries.shared', $summary->share_token),
        ]);
    }

    /**
     * Serve one card as a PNG. Rendered on first request and cached on the
     * public disk, so nobody pays for thirty images a reader will never open.
     *
     * `?preview=1` paints the file instead of saving it: the screen shows the
     * same 4:5 render it offers for download, so one route covers both.
     */
    public function card(Request $request, MonthlySummary $summary, string $card, string $format, string $theme): StreamedResponse
    {
        abort_unless($summary->user_id === $request->user()->id, 404);

        $cardType = MonthlySummaryCard::tryFrom($card);
        $formatType = CardFormat::tryFrom($format);
        $themeType = CardTheme::tryFrom($theme);
        abort_if($cardType === null || $themeType === null, 404);
        // Only the shapes the screen offers. Wide is drawn by nothing now, so
        // serving it would mean starting Chromium for a picture no button asks
        // for — see CardFormat::shareable().
        abort_unless($formatType !== null && in_array($formatType, CardFormat::shareable(), strict: true), 404);

        // A month with no savings goal has no savings-goal card, whatever the URL says.
        abort_unless($this->picker->canDraw($cardType, $summary->payload), 404);

        try {
            $path = $this->renderer->path($summary, $cardType, $formatType, $themeType, $this->analysis->eligible($request->user()));
        } catch (Throwable $exception) {
            report($exception);
            abort(503);
        }

        $disk = Storage::disk('public');

        if ($request->boolean('preview')) {
            // Minutes, not days: `CardRenderer::forget()` exists so a redesign or
            // a corrected figure replaces the picture, and a long-lived copy in
            // the browser would outlive it.
            return $disk->response($path, headers: ['Cache-Control' => 'private, max-age=300']);
        }

        return $disk->download($path, $this->filename($summary, $cardType, $formatType, $themeType));
    }

    /**
     * Mint the public link. Deliberately an explicit action: until it is taken,
     * the summary has no URL anybody outside the account could open.
     */
    public function share(Request $request, MonthlySummary $summary): RedirectResponse
    {
        abort_unless($summary->user_id === $request->user()->id, 404);

        $summary->mintShareToken();

        return back();
    }

    public function revoke(Request $request, MonthlySummary $summary): RedirectResponse
    {
        abort_unless($summary->user_id === $request->user()->id, 404);

        $summary->revokeShareToken();

        return back();
    }

    /**
     * Put the dashboard notice away. Answers with no content on purpose: the
     * dashboard hides the banner locally, and a redirect would make Inertia
     * reload every deferred prop on the page just to hide one row.
     */
    public function dismiss(Request $request, MonthlySummary $summary): HttpResponse
    {
        abort_unless($summary->user_id === $request->user()->id, 404);

        $summary->dismiss();
        $this->notifications->markReadForSummary($summary);

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(MonthlySummary $summary): array
    {
        return [
            'id' => $summary->id,
            'period' => $summary->period,
            'card' => $summary->card->value,
            'complete' => $summary->complete,
            'sent_at' => $summary->sent_at?->toIso8601String(),
            'shared' => $summary->share_token !== null,
            'payload' => $summary->payload,
        ];
    }

    /**
     * The chosen card first, then the alternatives, each with the one picture the
     * grid paints.
     *
     * Only a thumbnail: the shape, the skin and the two buttons all live in the
     * share dialog now, which builds its own URLs off the same route, so there is
     * nothing here for the screen to pick from. The grid's job is choosing WHICH
     * card to post, and that is what a thumbnail answers.
     *
     * @return list<array{card: string, chosen: bool, preview: string}>
     */
    private function cardOptions(MonthlySummary $summary): array
    {
        $cards = [$summary->card, ...$this->picker->alternatives($summary->payload, $summary->card)];

        return array_map(fn (MonthlySummaryCard $card): array => [
            'card' => $card->value,
            'chosen' => $card === $summary->card,
            'preview' => route('monthly-summaries.card', [
                $summary, $card->value, CardFormat::default()->value, CardTheme::default()->value, 'preview' => 1,
            ]),
        ], $cards);
    }

    private function filename(MonthlySummary $summary, MonthlySummaryCard $card, CardFormat $format, CardTheme $theme): string
    {
        return "whisper-money-{$summary->period}-{$card->value}-{$format->value}-{$theme->value}.png";
    }
}
