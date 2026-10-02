<?php

namespace App\Http\Responses;

use App\Services\AuthEntryPointService;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function __construct(private readonly AuthEntryPointService $authEntryPointService) {}

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse($request): Response
    {
        $this->authEntryPointService->queueReturningUserCookie();

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : $this->authEntryPointService->redirectToIntended();
    }
}
