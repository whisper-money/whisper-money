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
        $institutions = $provider->getInstitutions($request->validated('country'));

        return response()->json($institutions);
    }
}
