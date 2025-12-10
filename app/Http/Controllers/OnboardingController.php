<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function index(Request $request): Response
    {
        $banks = Bank::query()
            ->whereNull('user_id')
            ->orWhere('user_id', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name', 'logo']);

        return Inertia::render('onboarding/index', [
            'banks' => $banks,
        ]);
    }

    public function complete(Request $request): RedirectResponse
    {
        $request->user()->update([
            'onboarded_at' => now(),
        ]);

        return redirect()->route('dashboard');
    }
}
