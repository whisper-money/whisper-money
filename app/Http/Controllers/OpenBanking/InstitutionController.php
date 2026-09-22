<?php

namespace App\Http\Controllers\OpenBanking;

use App\Contracts\BankingProviderInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpenBanking\ListInstitutionsRequest;
use Illuminate\Http\JsonResponse;

class InstitutionController extends Controller
{
    public function index(ListInstitutionsRequest $request, BankingProviderInterface $provider): JsonResponse
    {
        // An install without open banking has no catalogue rather than a broken
        // one. The picker merges this list with the API-key providers it knows
        // by itself, so an empty answer still leaves Coinbase or Wise to pick;
        // an error would take the whole dialog down with it.
        if (! config('services.enablebanking.enabled')) {
            return response()->json([]);
        }

        $institutions = $provider->getInstitutions($request->validated('country'));

        return response()->json($institutions);
    }
}
