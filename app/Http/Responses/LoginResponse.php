<?php

namespace App\Http\Responses;

use App\Services\AuthEntryPointService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

class LoginResponse implements LoginResponseContract
{
    public function __construct(private readonly AuthEntryPointService $authEntryPointService) {}

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        session()->flash('show_encryption_prompt', true);
        $this->authEntryPointService->queueReturningUserCookie();

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended(Fortify::redirects('login'));
    }
}
