<?php

namespace App\Http\Controllers;

use App\Enums\MonthlySummaryCard;
use App\Enums\MonthlySummaryFormat;
use App\Features\MonthlySummaries;
use App\Models\MonthlySummary;
use App\Services\MonthlySummary\AnalysisWriter;
use App\Services\MonthlySummary\CardPicker;
use App\Services\MonthlySummary\CardRenderer;
use App\Services\MonthlySummary\EmailPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;
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
            'cards' => $this->cardOptions($summary, $pro),
            'shareUrl' => $summary->share_token === null ? null : route('monthly-summaries.shared', $summary->share_token),
        ]);
    }

    /**
     * Serve one card as a PNG. Rendered on first request and cached on the
     * public disk, so nobody pays for fifteen images a reader will never open.
     */
    public function card(Request $request, MonthlySummary $summary, string $card, string $format)
    {
        abort_unless($summary->user_id === $request->user()->id, 404);

        $cardType = MonthlySummaryCard::tryFrom($card);
        $formatType = MonthlySummaryFormat::tryFrom($format);
        abort_if($cardType === null || $formatType === null, 404);

        // A month with no savings goal has no savings-goal card, whatever the URL says.
        abort_unless($this->picker->canDraw($cardType, $summary->payload), 404);

        try {
            $path = $this->renderer->path($summary, $cardType, $formatType, $this->analysis->eligible($request->user()));
        } catch (Throwable $exception) {
            report($exception);
            abort(503);
        }

        return Storage::disk('public')->download($path, $this->filename($summary, $cardType, $formatType));
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
     * The chosen card first, then the alternatives, each with the three formats
     * it can be downloaded in.
     *
     * @return list<array<string, mixed>>
     */
    private function cardOptions(MonthlySummary $summary, bool $pro): array
    {
        $cards = [$summary->card, ...$this->picker->alternatives($summary->payload, $summary->card)];

        return array_map(fn (MonthlySummaryCard $card): array => [
            'card' => $card->value,
            'chosen' => $card === $summary->card,
            'formats' => array_map(fn (MonthlySummaryFormat $format): array => [
                'format' => $format->value,
                'url' => route('monthly-summaries.card', [$summary, $card->value, $format->value]),
            ], MonthlySummaryFormat::cases()),
        ], $cards);
    }

    private function filename(MonthlySummary $summary, MonthlySummaryCard $card, MonthlySummaryFormat $format): string
    {
        return "whisper-money-{$summary->period}-{$card->value}-{$format->value}.png";
    }
}
