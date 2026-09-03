<?php

namespace App\Http\Controllers;

use App\Enums\MonthlySummaryCard;
use App\Enums\MonthlySummaryFormat;
use App\Enums\MonthlySummaryTheme;
use App\Features\MonthlySummaries;
use App\Models\MonthlySummary;
use App\Services\MonthlySummary\AnalysisWriter;
use App\Services\MonthlySummary\CardPicker;
use App\Services\MonthlySummary\CardRenderer;
use App\Services\MonthlySummary\EmailPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;
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
    ) {}

    public function index(Request $request): Response
    {
        abort_unless(Feature::active(MonthlySummaries::class), 404);

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
        abort_unless(Feature::active(MonthlySummaries::class), 404);
        abort_unless($summary->user_id === $request->user()->id, 404);

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
        $formatType = MonthlySummaryFormat::tryFrom($format);
        $themeType = MonthlySummaryTheme::tryFrom($theme);
        abort_if($cardType === null || $formatType === null || $themeType === null, 404);

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
     * The chosen card first, then the alternatives, each in both themes: the
     * screen carries one light/dark switch for the whole section and flips every
     * preview and every download link with it, without a round trip.
     *
     * @return list<array<string, mixed>>
     */
    private function cardOptions(MonthlySummary $summary): array
    {
        $cards = [$summary->card, ...$this->picker->alternatives($summary->payload, $summary->card)];

        return array_map(fn (MonthlySummaryCard $card): array => [
            'card' => $card->value,
            'chosen' => $card === $summary->card,
            'themes' => collect(MonthlySummaryTheme::cases())
                ->mapWithKeys(fn (MonthlySummaryTheme $theme): array => [
                    $theme->value => $this->cardLinks($summary, $card, $theme),
                ])
                ->all(),
        ], $cards);
    }

    /**
     * The picture the screen paints and the three files it offers, for one card
     * in one theme.
     *
     * @return array{preview: string, formats: list<array{format: string, url: string}>}
     */
    private function cardLinks(MonthlySummary $summary, MonthlySummaryCard $card, MonthlySummaryTheme $theme): array
    {
        return [
            'preview' => route('monthly-summaries.card', [
                $summary, $card->value, MonthlySummaryFormat::default()->value, $theme->value, 'preview' => 1,
            ]),
            'formats' => array_map(fn (MonthlySummaryFormat $format): array => [
                'format' => $format->value,
                'url' => route('monthly-summaries.card', [$summary, $card->value, $format->value, $theme->value]),
            ], MonthlySummaryFormat::cases()),
        ];
    }

    private function filename(MonthlySummary $summary, MonthlySummaryCard $card, MonthlySummaryFormat $format, MonthlySummaryTheme $theme): string
    {
        return "whisper-money-{$summary->period}-{$card->value}-{$format->value}-{$theme->value}.png";
    }
}
