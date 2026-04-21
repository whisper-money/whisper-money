<?php

namespace App\Http\Responses;

use App\Services\AuthEntryPointService;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse implements RegisterResponseContract
{
    public function __construct(private readonly AuthEntryPointService $authEntryPointService) {}

    public function toResponse($request): Response
    {
        $this->authEntryPointService->queueReturningUserCookie();

        if ($request->wantsJson()) {
            return new JsonResponse('', 201);
        }

        return redirect()->route('onboarding');
    }
}
