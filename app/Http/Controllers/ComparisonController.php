<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Public competitor comparison landings. The copy lives in the frontend data
 * module `resources/js/data/comparison-pages.ts`; this controller only owns the
 * slugs, so an unknown one 404s instead of rendering an empty page.
 */
class ComparisonController extends Controller
{
    /**
     * Kept in sync with the data module by ComparisonPageTest.
     *
     * @var list<string>
     */
    public const SLUGS = [
        'alternativa-a-fintonic',
        'alternativa-a-ynab-en-espanol',
        'alternativa-a-excel-y-google-sheets',
        'alternativa-a-la-app-de-tu-banco',
        'alternativa-a-wallet-budgetbakers',
        'alternativa-a-monefy',
        'margen-vs-whisper-money',
        'dinerio-vs-whisper-money',
    ];

    public function show(string $slug): Response
    {
        return Inertia::render('comparison', [
            'slug' => $slug,
            'canRegister' => config('auth.registration_enabled'),
        ]);
    }
}
