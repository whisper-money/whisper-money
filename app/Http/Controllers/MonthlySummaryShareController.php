<?php

namespace App\Http\Controllers;

use App\Enums\MonthlySummaryFormat;
use App\Models\MonthlySummary;
use App\Services\MonthlySummary\AnalysisWriter;
use App\Services\MonthlySummary\CardRenderer;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use Throwable;

/**
 * The public page behind a shared card.
 *
 * It carries the picture and a link to the product, and nothing else: no name,
 * no amounts, no breakdown. Its whole reason to exist is that pasting the link
 * into X or WhatsApp unfurls the image without the sharer having to download and
 * re-upload anything — so it is `noindex`, and the URL only exists once the
 * owner has asked for it.
 */
class MonthlySummaryShareController extends Controller
{
    public function __construct(
        private CardRenderer $renderer,
        private AnalysisWriter $analysis,
    ) {}

    public function __invoke(string $token): Response
    {
        $summary = MonthlySummary::query()->where('share_token', $token)->firstOrFail();

        try {
            $imageUrl = $this->renderer->url(
                $summary,
                $summary->card,
                MonthlySummaryFormat::default(),
                $this->analysis->eligible($summary->user),
            );
        } catch (Throwable $exception) {
            report($exception);

            abort(503);
        }

        return response(View::make('monthly-summaries.shared', [
            'imageUrl' => $imageUrl,
            'period' => $summary->period,
        ])->render())->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
