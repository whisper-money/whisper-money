<?php

namespace App\Http\Responses;

use App\Services\AuthEntryPointService;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    public function __construct(private readonly AuthEntryPointService $authEntryPointService) {}

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse($request): Response
    {
        $this->authEntryPointService->queueReturningUserCookie();

        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : $this->authEntryPointService->redirectToIntended();
    }
}
