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
        $user = $request->user();

        $banks = Bank::query()
            ->whereNull('user_id')
            ->orWhere('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'logo']);

        $accounts = $user->accounts()
            ->with('bank:id,name,logo')
            ->get(['id', 'name', 'name_iv', 'type', 'currency_code', 'bank_id']);

        return Inertia::render('onboarding/index', [
            'banks' => $banks,
            'accounts' => $accounts,
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
