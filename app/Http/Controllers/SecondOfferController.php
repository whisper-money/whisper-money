<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Records that the second Pro offer — the dialog a free user meets after
 * their first import — was shown, so it is shown once and never again.
 *
 * Stamped by the dialog as it opens rather than when the shared prop is
 * computed: a prop resolved for a request that never painted (a redirect, a
 * partial reload) must not use up the one chance to show it. Kept on the user
 * so seeing it on the laptop also counts on the phone.
 */
class SecondOfferController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $request->user()->markSecondOfferShown();

        return response()->noContent();
    }
}
